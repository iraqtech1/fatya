<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publication is independent of enabling external financial transactions. */
class DigitalPublications
{
    public function bulk(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $store = app(Store::class);
            $s = $store->read(true);
            if (($b['revision'] ?? null) !== $s['revision']) {
                throw new ApiError('البيانات تغيرت؛ حدّث الفئات قبل تطبيق الإجراء', 409);
            }
            $action = $b['action'] ?? null;
            $items = $b['items'] ?? null;
            if (! in_array($action, ['publish', 'unpublish', 'price'], true) || ! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 5000) {
                throw new ApiError('اختر الإجراء وحدد الفئات المطلوبة', 422);
            }
            $record = app(DigitalProviders::class)->catalogRecord('topup');
            $catalog = $record ? json_decode($record->snapshot, true) : [];
            if ($action !== 'unpublish' && (! $catalog || ($catalog['hash'] ?? '') !== ($b['catalogHash'] ?? null))) {
                throw new ApiError('كتالوج المجهّز تغير؛ حدّث الفئات وراجع المعاينة', 409);
            }
            $offers = collect($catalog['offers'] ?? [])->keyBy(fn ($o) => $o['type'].':'.$o['providerId']);
            $existing = DB::table('digital_publications')->where('connection', 'topup')->get()->keyBy('identity');
            $products = collect($s['products'])->keyBy(fn ($p) => (string) $p['id']);
            $target = $b['productId'] ?? '';
            if (! is_string($target)) {
                throw new ApiError('بطاقة العرض غير صحيحة', 422);
            }
            $rows = [];
            $seen = [];
            foreach ($items as $item) {
                if (! is_array($item) || ! is_string($item['providerId'] ?? null) || ! preg_match('/^[0-9]{1,100}$/D', $item['providerId']) || ! in_array($item['type'] ?? null, ['topup', 'bundle', 'bill'], true) || ! is_int($item['version'] ?? null)) {
                    throw new ApiError('معرّفات التحديد غير صحيحة', 422);
                }
                $identity = hash('sha256', Store::json(['topup', $item['type'], $item['providerId']]));
                if (isset($seen[$identity])) {
                    throw new ApiError('التحديد يتضمن فئة مكررة', 422);
                }$seen[$identity] = true;
                $old = $existing->get($identity);
                if ($item['version'] !== (int) ($old?->version ?? 0)) {
                    throw new ApiError('حالة إحدى الفئات المحددة تغيرت؛ لم يطبق أي تغيير', 409);
                }
                if ($action === 'unpublish') {
                    if (! $old || ! $old->active) {
                        continue;
                    }
                    $rows[] = [...(array) $old, 'active' => false, 'version' => (int) $old->version + 1, 'updated' => time()];

                    continue;
                }
                $offer = $offers->get($item['type'].':'.$item['providerId']);
                if (! $offer || ($action === 'publish' && ! $offer['available'])) {
                    throw new ApiError('إحدى الفئات المحددة غير متاحة للنشر؛ راجع الفلترة', 422);
                }
                $productId = $target !== '' ? $target : $old?->product_id;
                $p = $products->get($productId);
                if (! $p || ! $p['active']) {
                    throw new ApiError('حدد بطاقة عرض منشورة للفئات الجديدة', 422);
                }
                $mode = $b['priceMode'] ?? 'existing';
                if ($mode === 'existing') {
                    $price = $old?->retail;
                    if ($price === null) {
                        throw new ApiError('بعض الفئات ليس لها سعر بيع محفوظ؛ اختر طريقة التسعير', 422);
                    }$price = StockOrders::money((string) $price);
                } elseif ($mode === 'supplier') {
                    $price = $offer['cost'];
                } elseif ($mode === 'fixed') {
                    $price = StockOrders::money($b['retail'] ?? null);
                } elseif ($mode === 'markup') {
                    $percent = StockOrders::money($b['percent'] ?? null);
                    $flat = StockOrders::money($b['flat'] ?? null);
                    if (BigDecimal::of($percent)->isGreaterThan(1000) || $offer['cost'] === null) {
                        throw new ApiError('نسبة الزيادة أو تكلفة المجهّز غير صحيحة', 422);
                    }
                    $price = (string) BigDecimal::of($offer['cost'])->multipliedBy(BigDecimal::of(100)->plus($percent))->dividedBy(100, 3, RoundingMode::HalfUp)->plus($flat)->toScale(3);
                } else {
                    throw new ApiError('طريقة التسعير غير صحيحة', 422);
                }
                if ($price === null || ! BigDecimal::of($price)->isGreaterThan(0)) {
                    throw new ApiError('سعر البيع لكل فئة يجب أن يكون أكبر من صفر', 422);
                }
                $price = StockOrders::money($price);
                if (request()->attributes->has('adminAccess')) {
                    app(AdminPolicy::class)->digitalOffer($old, ['product_id' => (string) $p['id'], 'provider_id' => $offer['providerId'], 'connection' => 'topup', 'retail' => $price, 'active' => $action === 'publish' ? true : (bool) ($old?->active ?? false)], true);
                }
                $rows[] = ['id' => $old?->id ?? (string) Str::uuid(), 'identity' => $identity, 'product_id' => (string) $p['id'], 'connection' => 'topup', 'provider_id' => $offer['providerId'], 'snapshot' => Store::json($offer), 'retail' => $price, 'active' => $action === 'publish' ? true : (bool) ($old?->active ?? false), 'version' => (int) ($old?->version ?? 0) + 1, 'updated' => time()];
            }
            // Validate every selected row before writing any publication or price.
            foreach (array_chunk($rows, 100) as $chunk) {
                DB::table('digital_publications')->upsert($chunk, ['identity'], ['product_id', 'snapshot', 'retail', 'active', 'version', 'updated']);
            }
            if ($rows) {
                ProcurementAudit::record('digital.bulk.'.$action, null, ['count' => count($rows), 'selectionHash' => hash('sha256', Store::json(array_keys($seen))), 'priceMode' => $b['priceMode'] ?? 'existing']);
                $store->event($s, 'إجراء جماعي على '.count($rows).' فئات TOPUP: '.$action);
                $store->save($s);
            }

            return ['publications' => $this->listing(), 'revision' => $s['revision'], 'changed' => count($rows)];
        }, 3);
    }

    public function listing(): array
    {
        return DB::table('digital_publications')->orderBy('updated')->get()->map(fn ($r) => ['id' => $r->id, 'productId' => $r->product_id, 'provider' => $r->connection, 'providerId' => $r->provider_id, 'snapshot' => json_decode($r->snapshot, true), 'retail' => (string) $r->retail, 'published' => (bool) $r->active, 'version' => (int) $r->version])->all();
    }

    public function catalogs(): array
    {
        return DB::table('digital_catalog')->get()->map(function ($r) {
            $s = json_decode($r->snapshot, true);
            unset($s['encryptedSource']);

            return $s;
        })->all();
    }

    public function save(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $store = app(Store::class);
            $s = $store->read(true);
            if (($b['revision'] ?? null) !== $s['revision']) {
                throw new ApiError('البيانات تغيرت؛ حدّث الصفحة', 409);
            }
            if (($b['provider'] ?? null) !== 'topup' || ! is_bool($b['published'] ?? null)) {
                throw new ApiError('بيانات النشر غير صحيحة', 422);
            }
            if (($b['published'] ?? null) === false && isset($b['id'])) {
                if (! is_string($b['id']) || ! Str::isUuid($b['id'])) {
                    throw new ApiError('معرّف النشر غير صحيح', 422);
                }
                $old = DB::table('digital_publications')->where('id', $b['id'])->first();
                if (! $old || (int) $old->version !== ($b['version'] ?? null)) {
                    throw new ApiError('حالة النشر تغيرت؛ حدّث الصفحة', 409);
                }
                DB::table('digital_publications')->where('id', $old->id)->update(['active' => false, 'version' => (int) $old->version + 1, 'updated' => time()]);
                ProcurementAudit::record('digital.unpublish', $old->id, []);
                $store->event($s, 'إيقاف نشر خدمة TOPUP');
                $store->save($s);

                return ['publications' => $this->listing(), 'revision' => $s['revision']];
            }
            $record = app(DigitalProviders::class)->catalogRecord('topup');
            $catalog = $record ? json_decode($record->snapshot, true) : [];
            if (! $catalog || ($catalog['hash'] ?? '') !== ($b['catalogHash'] ?? null)) {
                throw new ApiError('الكتالوج تغير؛ راجع بيانات الفئة', 409);
            }
            $offer = collect($catalog['offers'])->first(fn ($o) => $o['providerId'] === ($b['providerId'] ?? null) && $o['type'] === ($b['type'] ?? null));
            $product = collect($s['products'])->first(fn ($p) => (string) $p['id'] === ($b['productId'] ?? null) && $p['active']);
            if (! $offer || ! $product || ($b['published'] && ! $offer['available'])) {
                throw new ApiError('اختر فئة قابلة للبيع وبطاقة منشورة لعرضها تحتها', 422);
            }
            $price = StockOrders::money($b['retail'] ?? null);
            if (! BigDecimal::of($price)->isGreaterThan(0)) {
                throw new ApiError('حدد سعر بيع أكبر من صفر', 422);
            }
            $identity = hash('sha256', Store::json(['topup', $offer['type'], $offer['providerId']]));
            $old = DB::table('digital_publications')->where('identity', $identity)->first();
            if (($b['version'] ?? 0) !== (int) ($old?->version ?? 0)) {
                throw new ApiError('حالة النشر تغيرت؛ حدّث الصفحة', 409);
            }
            if (request()->attributes->has('adminAccess')) {
                app(AdminPolicy::class)->digitalOffer($old, ['product_id' => (string) $product['id'], 'provider_id' => $offer['providerId'], 'connection' => 'topup', 'retail' => $price, 'active' => $b['published']], true);
            }
            $id = $old?->id ?? (string) Str::uuid();
            DB::table('digital_publications')->updateOrInsert(['identity' => $identity], ['id' => $id, 'product_id' => (string) $product['id'], 'connection' => 'topup', 'provider_id' => $offer['providerId'], 'snapshot' => Store::json($offer), 'retail' => $price, 'active' => $b['published'], 'version' => (int) ($old?->version ?? 0) + 1, 'updated' => time()]);
            ProcurementAudit::record($b['published'] ? 'digital.publish' : 'digital.unpublish', $id, ['productId' => $product['id'], 'retail' => $price]);
            $store->event($s, ($b['published'] ? 'نشر خدمة TOPUP: ' : 'إيقاف نشر خدمة TOPUP: ').$offer['title']);
            $store->save($s);

            return ['publications' => $this->listing(), 'revision' => $s['revision']];
        });
    }

    public function decorate(array &$s): void
    {
        $catalog = app(DigitalProviders::class)->catalogRecord('topup');
        $fresh = $catalog ? json_decode($catalog->snapshot, true) : [];
        $offers = collect($fresh['offers'] ?? [])->keyBy(fn ($o) => $o['type'].':'.$o['providerId']);
        $configured = collect(app(DigitalProviders::class)->status())->firstWhere('id', 'topup')['configured'] ?? false;
        foreach (DB::table('digital_publications')->where('active', true)->get() as $r) {
            $saved = json_decode($r->snapshot, true);
            $o = $offers->get($saved['type'].':'.$r->provider_id);
            foreach ($s['products'] as &$p) {
                if ((string) $p['id'] !== $r->product_id || ! $p['active']) {
                    continue;
                }
                $ready = $configured && $o && $o['available'] && ($o['cost'] === $saved['cost']) && config('digital.manual_topup_enabled');
                // No wholesale price, raw provider details, connection settings or credentials enter public state.
                $p['providerServices'][] = ['id' => $r->id, 'type' => $saved['type'], 'title' => $o['title'] ?? $saved['title'], 'description' => $o['description'] ?? $saved['description'] ?? '', 'images' => $o['images'] ?? $saved['images'] ?? [], 'serviceInfo' => $o['serviceInfo'] ?? $saved['serviceInfo'] ?? [], 'price' => (string) $r->retail, 'purchasable' => (bool) $ready, 'payment' => 'Manual'];
            } unset($p);
        }
    }

    public function reserve(array $s, array $customer, array $b, array $p): array
    {
        if (! is_string($b['publicationId'] ?? null) || ! Str::isUuid($b['publicationId'])) {
            throw new ApiError('معرّف الخدمة غير صحيح', 422);
        }
        $r = DB::table('digital_publications')->where('id', $b['publicationId'])->where('product_id', (string) $p['id'])->where('active', true)->first();
        if (! $r || ! config('digital.manual_topup_enabled') || ($b['payment'] ?? null) !== 'Manual' || ($b['denom'] ?? null) !== 0) {
            throw new ApiError('الخدمة غير منشورة أو الدفع اليدوي غير متاح', 409);
        }
        if ((! is_string($b['quotedAmount'] ?? null) && ! is_int($b['quotedAmount'] ?? null) && ! is_float($b['quotedAmount'] ?? null)) || StockOrders::money((string) $b['quotedAmount']) !== StockOrders::money((string) $r->retail)) {
            throw new ApiError('سعر البيع تغير؛ حدّث التطبيق وراجع السعر الجديد', 409);
        }
        $offer = json_decode($r->snapshot, true);
        $p['name'] .= ' · '.$offer['title'];
        $p['unit'] = 'خدمة';
        $p['values'] = [null];

        return app(DigitalSales::class)->reserve($s, $customer, $b, $p, 0, $r);
    }
}
