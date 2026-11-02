<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

class ImportCategories
{
    public static function code(mixed $code): string
    {
        if (! is_string($code)) {
            throw new ApiError('رمز الفئة غير صحيح', 422);
        }
        $code = preg_replace('/^(EVS|EVD)-/', '', strtoupper(trim($code)));
        if (! preg_match('/^[A-Z][A-Z0-9-]{0,39}$/D', $code)) {
            throw new ApiError('رمز الفئة غير صحيح', 422);
        }

        return $code;
    }

    public function read(): array
    {
        return ['reference' => json_decode(file_get_contents(database_path('seeders/asiacell-import-catalog.json')), true, 512, JSON_THROW_ON_ERROR), 'mappings' => DB::table('stock_category_codes')->get()->map(fn ($r) => ['code' => $r->code, 'productId' => $r->product_id, 'value' => (float) $r->denomination])->all()];
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
                if (! $p || ! is_int($di) || ! isset($p['values'][$di]) || isset($next[$code])) {
                    throw new ApiError('اختر فئة صحيحة دون تكرار رمز', 422);
                }
                $next[$code] = ['code' => $code, 'product_id' => (string) $p['id'], 'denomination' => $p['values'][$di]];
            }
            DB::table('stock_category_codes')->delete();
            if ($next) {
                DB::table('stock_category_codes')->insert(array_values($next));
            }
            $store->event($s, 'تحديث رموز فئات ملفات المجهّز');
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
        $mapping = DB::table('stock_category_codes')->where('code', $code)->first();
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
