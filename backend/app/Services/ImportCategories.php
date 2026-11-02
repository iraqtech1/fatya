<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

class ImportCategories
{
    public static function scope(array $product): string
    {
        return empty($product['manufacturerId']) ? '*' : 'manufacturer:'.$product['manufacturerId'];
    }

    public static function code(mixed $code): string
    {
        if (! is_string($code)) {
            throw new ApiError('رمز الفئة غير صحيح', 422);
        }
        $code = preg_replace('/^(EVS|EVD)-/', '', mb_strtoupper(trim(preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $code))));
        if (! preg_match('/^[\p{L}\p{N}][\p{L}\p{N}._-]{0,39}$/uD', $code)) {
            throw new ApiError('رمز الفئة غير صحيح', 422);
        }

        return $code;
    }

    public function forProduct(array $product): array
    {
        $rows = DB::table('stock_category_codes')->where('product_id', (string) $product['id'])->orderBy('code')->get();

        return array_map(fn ($value) => implode(', ', $rows->filter(fn ($r) => (float) $r->denomination === (float) $value)->pluck('code')->all()), $product['values']);
    }

    public function attach(array &$state): void
    {
        $group = [];
        foreach (DB::table('stock_category_codes')->orderBy('code')->get() as $row) {
            $group[$row->product_id][(string) (float) $row->denomination][] = $row->code;
        }
        foreach ($state['products'] as &$product) {
            $product['denomImportCodes'] = array_map(fn ($v) => implode(', ', $group[(string) $product['id']][(string) (float) $v] ?? []), $product['values']);
        } unset($product);
    }

    public function syncProduct(array $product): void
    {
        $before = DB::table('stock_category_codes')->where('product_id', (string) $product['id'])->orderBy('code')->get()->toArray();
        $next = [];
        foreach ($product['values'] as $i => $value) {
            foreach (ProductFields::codes($product['denomImportCodes'][$i] ?? '') as $code) {
                if (isset($next[$code])) {
                    throw new ApiError('معرّف الفئة مكرر بين فئات البطاقة', 422);
                }
                $next[$code] = ['code' => $code, 'scope' => self::scope($product), 'product_id' => (string) $product['id'], 'denomination' => $value];
            }
        }
        if ($next && DB::table('stock_category_codes')->where('scope', self::scope($product))->whereIn('code', array_keys($next))->where('product_id', '!=', (string) $product['id'])->exists()) {
            throw new ApiError('أحد المعرّفات مربوط ببطاقة أخرى؛ عدّل المعرّف أو ربطه أولاً', 409);
        }
        if (DB::table('stock_category_codes')->where('product_id', '!=', (string) $product['id'])->count() + count($next) > 500) {
            throw new ApiError('الحد الأقصى 500 معرّف فئة في المتجر', 422);
        }
        DB::table('stock_category_codes')->where('product_id', (string) $product['id'])->delete();
        if ($next) {
            DB::table('stock_category_codes')->insert(array_values($next));
        }
        $after = DB::table('stock_category_codes')->where('product_id', (string) $product['id'])->orderBy('code')->get()->toArray();
        if ($before != $after) {
            ProcurementAudit::record('category_mapping', (string) $product['id'], ['before' => $before, 'after' => $after]);
        }
    }

    public function read(): array
    {
        return ['reference' => json_decode(file_get_contents(database_path('seeders/asiacell-import-catalog.json')), true, 512, JSON_THROW_ON_ERROR), 'mappings' => DB::table('stock_category_codes')->orderBy('code')->get()->map(fn ($r) => ['code' => $r->code, 'scope' => $r->scope, 'productId' => $r->product_id, 'value' => (float) $r->denomination])->all()];
    }

    public function add(array $body): array
    {
        return DB::transaction(function () use ($body) {
            $store = app(Store::class);
            $state = $store->read(true);
            if (($body['revision'] ?? null) !== $state['revision']) {
                throw new ApiError('البيانات تغيرت؛ حدّث الصفحة', 409);
            }
            $code = self::code($body['code'] ?? null);
            $product = collect($state['products'])->first(fn ($p) => (string) $p['id'] === (string) ($body['productId'] ?? ''));
            $index = $body['denom'] ?? null;
            if (! $product || ! is_int($index) || ! isset($product['values'][$index])) {
                throw new ApiError('اختر البطاقة والفئة أولاً', 422);
            }
            $old = DB::table('stock_category_codes')->where('scope', self::scope($product))->where('code', $code)->first();
            if ($old && ($old->product_id !== (string) $product['id'] || (float) $old->denomination !== (float) $product['values'][$index])) {
                throw new ApiError('المعرّف مرتبط بفئة أخرى', 409);
            }
            if (! $old) {
                if (DB::table('stock_category_codes')->count() >= 500) {
                    throw new ApiError('الحد الأقصى 500 معرّف', 422);
                }
                DB::table('stock_category_codes')->insert(['code' => $code, 'scope' => self::scope($product), 'product_id' => (string) $product['id'], 'denomination' => $product['values'][$index]]);
                ProcurementAudit::record('category_mapping_added', (string) $product['id'], ['code' => $code, 'value' => $product['values'][$index]]);
                $store->event($state, 'إضافة معرّف فئة من ملف طلبية');
                $store->save($state);
            }

            return $this->read() + ['revision' => $state['revision']];
        });
    }

    public function save(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $store = app(Store::class);
            $s = $store->read(true);
            if (($b['revision'] ?? null) !== $s['revision']) {
                throw new ApiError('البيانات تغيرت؛ حدّث الصفحة', 409);
            }
            $rows = $b['mappings'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 500) {
                throw new ApiError('الحد الأقصى 500 رمز', 422);
            }
            $next = [];
            foreach ($rows as $r) {
                $code = self::code($r['code'] ?? null);
                $p = collect($s['products'])->first(fn ($p) => (string) $p['id'] === (string) ($r['productId'] ?? ''));
                $di = $r['denom'] ?? null;
                $key = $p ? self::scope($p).':'.$code : $code;
                if (! $p || ! is_int($di) || ! isset($p['values'][$di]) || isset($next[$key])) {
                    throw new ApiError('اختر فئة صحيحة دون تكرار رمز', 422);
                }
                $next[$key] = ['code' => $code, 'scope' => self::scope($p), 'product_id' => (string) $p['id'], 'denomination' => $p['values'][$di]];
            }
            $before = DB::table('stock_category_codes')->orderBy('code')->get()->toArray();
            DB::table('stock_category_codes')->delete();
            if ($next) {
                DB::table('stock_category_codes')->insert(array_values($next));
            }
            $store->event($s, 'تحديث رموز فئات ملفات المجهّز');
            ProcurementAudit::record('category_mappings_saved', null, ['before' => $before, 'after' => array_values($next)]);
            $store->save($s);

            return $this->read() + ['revision' => $s['revision']];
        });
    }

    public function resolve(array $line, array $state): array
    {
        if (empty($line['categoryCode'])) {
            return $line;
        }
        $code = self::code($line['categoryCode']);
        $selected = collect($state['products'])->first(fn ($p) => (string) $p['id'] === (string) ($line['productId'] ?? ''));
        $query = DB::table('stock_category_codes')->where('code', $code);
        if ($selected) {
            $query->where('scope', self::scope($selected));
        } elseif (! empty($line['manufacturerId'])) {
            $query->where('scope', 'manufacturer:'.$line['manufacturerId']);
        }
        $matches = $query->get();
        if ($matches->count() > 1) {
            throw new ApiError('رمز الملف مستخدم لأكثر من شركة؛ حدد الشركة المصنّعة أولاً', 422);
        }
        $mapping = $matches->first();
        if (! $mapping) {
            if (empty($line['productId']) || ! is_int($line['denom'] ?? null)) {
                throw new ApiError('الرمز '.$code.' غير مربوط؛ اختر الفئة يدوياً', 422);
            }

            return $line;
        }
        $p = collect($state['products'])->first(fn ($p) => (string) $p['id'] === $mapping->product_id);
        $di = $p ? array_search((float) $mapping->denomination, $p['values']) : false;
        if ($di === false || ! $p['active']) {
            throw new ApiError('الفئة المرتبطة بالرمز موقوفة أو تغيرت', 409);
        }
        if (! empty($line['productId']) && ((string) $line['productId'] !== $mapping->product_id || ($line['denom'] ?? null) !== $di)) {
            throw new ApiError('اختيار الفئة لا يطابق رمز الملف؛ راجع الربط', 422);
        }

        return [...$line, 'productId' => $mapping->product_id, 'denom' => $di];
    }
}
