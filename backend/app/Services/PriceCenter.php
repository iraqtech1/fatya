<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Edits the existing catalog prices. It never rewrites inventory cost or sale snapshots. */
class PriceCenter
{
    private function rows(array $state): array
    {
        $rows = [];
        $mapped = DB::table('digital_offers')->get()->keyBy(fn ($row) => $row->product_id.':'.$row->denom_index);
        app(ImportCategories::class)->attach($state);
        $costs = DB::table('inventory_cards')->whereNull('sold_order_id')->whereNull('withdrawal_id')->selectRaw('product_id, denomination, MAX(unit_cost) as max_cost, MIN(unit_cost) as min_cost, COUNT(*) - COUNT(unit_cost) as unknown_count')->groupBy('product_id', 'denomination')->get()->keyBy(fn ($row) => $row->product_id.':'.StockOrders::money((string) $row->denomination));
        foreach ($state['products'] as $product) {
            $path = $this->path($state, $product);
            foreach ($product['values'] as $index => $denomination) {
                $mapping = $mapped->get($product['id'].':'.$index);
                $offer = $mapping ? json_decode($mapping->snapshot, true) : [];
                $cards = $costs->get($product['id'].':'.StockOrders::money((string) $denomination));
                $cost = $mapping ? ($offer['cost'] ?? null) : ($cards?->max_cost !== null ? StockOrders::money((string) $cards->max_cost) : null);
                $rows[] = ['key' => 'product:'.$product['id'].':'.$index, 'productId' => (string) $product['id'], 'denom' => $index, 'name' => $offer['title'] ?? $product['denomNames'][$index] ?? $product['name'].' '.$denomination, 'companyId' => $product['manufacturerId'] ?? '', 'companyPath' => $path, 'source' => $mapping?->connection ?? 'stock', 'remoteId' => $mapping?->provider_id, 'importCode' => $product['denomImportCodes'][$index] ?? '', 'value' => $denomination, 'retail' => StockOrders::money((string) ($mapping?->retail ?? $product['prices'][$index])), 'cost' => $cost, 'costMinimum' => $mapping ? $cost : ($cards?->min_cost !== null ? StockOrders::money((string) $cards->min_cost) : null), 'unknownCostCount' => $mapping ? ($cost === null ? 1 : 0) : (int) ($cards?->unknown_count ?? 0), 'costBasis' => $mapping ? 'catalog_snapshot' : 'remaining_stock_maximum', 'active' => $product['active'] && (! $mapping || $mapping->active), 'version' => $mapping?->updated, 'sourceHash' => $mapping ? hash('sha256', $mapping->snapshot) : null];
            }
        }
        foreach (DB::table('digital_publications')->get() as $publication) {
            $product = collect($state['products'])->first(fn ($p) => (string) $p['id'] === $publication->product_id);
            if (! $product) {
                continue;
            }
            $offer = json_decode($publication->snapshot, true);
            $rows[] = ['key' => 'publication:'.$publication->id, 'productId' => $publication->product_id, 'name' => $offer['title'], 'companyId' => $product['manufacturerId'] ?? '', 'companyPath' => $this->path($state, $product), 'source' => $publication->connection, 'remoteId' => $publication->provider_id, 'importCode' => '', 'value' => null, 'retail' => StockOrders::money((string) $publication->retail), 'cost' => $offer['cost'] ?? null, 'costMinimum' => $offer['cost'] ?? null, 'unknownCostCount' => ($offer['cost'] ?? null) === null ? 1 : 0, 'costBasis' => 'catalog_snapshot', 'active' => (bool) $publication->active, 'version' => (int) $publication->version, 'sourceHash' => hash('sha256', $publication->snapshot)];
        }

        return array_map(fn ($row) => $row + ['margin' => $row['cost'] !== null ? (string) BigDecimal::of($row['retail'])->minus($row['cost']) : null], $rows);
    }

    private function path(array $state, array $product): array
    {
        return array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'name', 'parentId', 'active'])), CompanyHierarchy::path($state['companies'], $product['manufacturerId'] ?? ''));
    }

    public function listing(): array
    {
        return DB::transaction(function () {
            $state = app(Store::class)->read();
            $rows = $this->rows($state);

            return ['revision' => $state['revision'], 'rows' => $rows, 'history' => DB::table('procurement_audit')->where('action', 'prices.updated')->orderByDesc('id')->limit(100)->get()->map(fn ($r) => ['id' => (string) $r->id, 'actor' => $r->actor, 'createdAt' => date(DATE_ATOM, $r->created), 'details' => json_decode($r->details, true)])->all()];
        });
    }

    private function permission(array $row): void
    {
        app(AdminAccess::class)->require($row['source'] === 'stock' ? 'products.prices' : 'digital.prices');
    }

    public function preview(array $input): array
    {
        $reason = CatalogEditor::text($input['reason'] ?? '', 500);
        $mode = $input['mode'] ?? 'set';
        $rounding = $input['rounding'] ?? 'nearest';
        if (! $reason || ! in_array($mode, ['set', 'fixed', 'percent', 'import'], true) || ! in_array($rounding, ['nearest', 'up', 'down'], true)) {
            throw new ApiError('حدد سبب التعديل وطريقة السعر والتقريب', 422);
        }
        $keys = $input['keys'] ?? [];
        if (! is_array($keys) || ! array_is_list($keys) || ! count($keys) || count($keys) > 1000 || count(array_unique($keys, SORT_REGULAR)) !== count($keys) || count(array_filter($keys, 'is_string')) !== count($keys)) {
            throw new ApiError('اختر من فئة إلى ألف فئة دون تكرار', 422);
        }
        $step = StockOrders::money($input['step'] ?? '0.001');
        $minimum = StockOrders::money($input['minimum'] ?? '0.001');
        if (BigDecimal::of($step)->isLessThanOrEqualTo(0) || BigDecimal::of($minimum)->isLessThanOrEqualTo(0)) {
            throw new ApiError('وحدة التقريب وأقل سعر يجب أن يكونا موجبين', 422);
        }
        if (isset($input['value']) && ! is_string($input['value']) && ! is_int($input['value']) && ! is_float($input['value'])) {
            throw new ApiError('قيمة التعديل غير صحيحة', 422);
        }
        if ($mode === 'import' && (! is_array($input['changes'] ?? null) || count($input['changes']) !== count($keys) || array_diff($keys, array_keys($input['changes'])))) {
            throw new ApiError('أسعار الملف لا تطابق الفئات المحددة', 422);
        }
        $value = (string) ($input['value'] ?? '0');
        if (! preg_match('/^-?\d{1,12}(?:\.\d{1,3})?$/D', $value)) {
            throw new ApiError('قيمة التعديل غير صحيحة', 422);
        }
        $override = ($input['allowBelowCost'] ?? false) === true;
        if ($override) {
            app(AdminAccess::class)->require('prices.below_cost');
        }

        return DB::transaction(function () use ($input, $reason, $mode, $rounding, $keys, $step, $minimum, $value, $override) {
            $state = app(Store::class)->read(true);
            if (($input['revision'] ?? null) !== $state['revision']) {
                throw new ApiError('الأسعار أو البيانات تغيرت؛ حدّث القائمة', 409);
            }
            $available = collect($this->rows($state))->keyBy('key');
            $changes = [];
            foreach ($keys as $key) {
                $row = $available->get($key);
                if (! $row) {
                    throw new ApiError('فئة غير موجودة في المعاينة', 422);
                }
                $this->permission($row);
                $old = BigDecimal::of($row['retail']);
                $price = match ($mode) {
                    'set' => BigDecimal::of($value),
                    'fixed' => $old->plus($value),
                    'percent' => $old->multipliedBy(BigDecimal::of(100)->plus($value))->dividedBy(100, 8, RoundingMode::Unnecessary),
                    'import' => BigDecimal::of(StockOrders::money($input['changes'][$key] ?? null)),
                };
                $round = match ($rounding) {
                    'up' => RoundingMode::Ceiling, 'down' => RoundingMode::Floor, default => RoundingMode::HalfUp
                };
                $price = $price->dividedBy($step, 0, $round)->multipliedBy($step)->toScale(3);
                if ($price->isLessThan($minimum)) {
                    throw new ApiError('السعر الجديد أقل من الحد الأدنى المحدد: '.$row['name'], 422);
                }
                $new = StockOrders::money((string) $price);
                $below = $row['cost'] !== null && $price->isLessThan($row['cost']);
                if ($below && (! $override || $row['source'] !== 'stock')) {
                    throw new ApiError('السعر أقل من التكلفة المعروفة: '.$row['name'].'؛ يحتاج صلاحية تجاوز مستقلة', 422);
                }
                $changes[] = ['key' => $key, 'name' => $row['name'], 'old' => $row['retail'], 'new' => $new, 'belowCost' => $below, 'unknownCost' => $row['cost'] === null || $row['unknownCostCount'] > 0, 'expected' => hash('sha256', Store::json($row))];
            }
            $id = (string) Str::uuid();
            Cache::put('price-preview:'.$id, ['actor' => app(AdminAccess::class)->current()['id'], 'revision' => $state['revision'], 'reason' => $reason, 'changes' => $changes, 'override' => $override], now()->addMinutes(10));

            return ['previewId' => $id, 'count' => count($changes), 'changes' => array_map(function ($row) {
                unset($row['expected']);

                return $row;
            }, $changes), 'reason' => $reason, 'expiresIn' => 600];
        });
    }

    public function apply(array $input): array
    {
        $id = $input['previewId'] ?? '';
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new ApiError('أنشئ معاينة أسعار صالحة أولاً', 422);
        }

        return DB::transaction(function () use ($id) {
            $store = app(Store::class);
            $state = $store->read(true);
            $preview = Cache::get('price-preview:'.$id);
            if (! $preview || $preview['actor'] !== app(AdminAccess::class)->current()['id'] || $preview['revision'] !== $state['revision']) {
                throw new ApiError('المعاينة انتهت أو تغيرت البيانات؛ أعد المعاينة', 409);
            }
            if ($preview['override']) {
                app(AdminAccess::class)->require('prices.below_cost');
            }
            $rows = collect($this->rows($state))->keyBy('key');
            foreach ($preview['changes'] as $change) {
                $row = $rows->get($change['key']);
                if (! $row || ! hash_equals($change['expected'], hash('sha256', Store::json($row)))) {
                    throw new ApiError('تغير سعر أو تكلفة فئة منذ المعاينة؛ أعد المعاينة', 409);
                }
                $this->permission($row);
            }
            foreach ($preview['changes'] as $change) {
                $row = $rows[$change['key']];
                if (str_starts_with($change['key'], 'publication:')) {
                    DB::table('digital_publications')->where('id', substr($change['key'], 12))->update(['retail' => $change['new'], 'version' => $row['version'] + 1, 'updated' => time()]);
                } else {
                    foreach ($state['products'] as &$product) {
                        if ((string) $product['id'] === $row['productId']) {
                            $product['prices'][$row['denom']] = $change['new'];
                            break;
                        }
                    } unset($product);
                    if ($row['source'] !== 'stock') {
                        DB::table('digital_offers')->where('product_id', $row['productId'])->where('denom_index', $row['denom'])->update(['retail' => $change['new'], 'updated' => time()]);
                    }
                }
            }
            $store->event($state, 'تعديل أسعار '.count($preview['changes']).' فئة');
            $store->save($state);
            ProcurementAudit::record('prices.updated', $id, ['reason' => $preview['reason'], 'revision' => $state['revision'], 'overrideBelowCost' => $preview['override'], 'changes' => array_map(function ($row) {
                unset($row['expected']);

                return $row;
            }, $preview['changes'])]);

            return ['changed' => count($preview['changes']), 'revision' => $state['revision']];
        });
    }
}
