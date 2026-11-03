<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;

/** Plan catalog changes in memory; persist them only with an approved stock batch. */
class StockOrderCatalog
{
    public function fingerprint(array $product): string
    {
        unset($product['stock']);

        return hash('sha256', Store::json($product));
    }

    public function prepare(array $line, array &$state): array
    {
        $index = collect($state['products'])->search(fn ($p) => (string) $p['id'] === (string) ($line['productId'] ?? ''));
        if ($index === false) {
            throw new ApiError('اختر البطاقة التابعة للشركة', 422);
        }
        $before = $state['products'][$index];
        $fingerprint = $this->fingerprint($before);
        $product = $before;
        $manufacturer = ($line['manufacturerId'] ?? '') ?: ($product['manufacturerId'] ?? '');
        if (! is_string($manufacturer)) {
            throw new ApiError('اختر الشركة المصنّعة', 422);
        }
        if (array_key_exists('retail', $line) && $manufacturer === '') {
            throw new ApiError('حدد الشركة المصنّعة للطلبية', 422);
        }
        if ($manufacturer !== '') {
            $company = collect($state['companies'])->first(fn ($c) => $c['id'] === $manufacturer);
            if (! $company || ! $company['active'] || ! in_array($company['role'], ['manufacturer', 'both'])) {
                throw new ApiError('اختر شركة مصنّعة مفعّلة', 422);
            }
            if (! empty($product['manufacturerId']) && $product['manufacturerId'] !== $manufacturer) {
                throw new ApiError('البطاقة لا تتبع الشركة المصنّعة المختارة', 422);
            }
            $product['manufacturerId'] = $manufacturer;
        }
        $new = ($line['denom'] ?? null) === -1;
        $request = array_intersect_key($line, array_flip(['productId', 'manufacturerId', 'denom', 'newValue', 'newName', 'retail', 'categoryCode']));
        if ($new) {
            if (! $manufacturer) {
                throw new ApiError('حدد الشركة المصنّعة للفئة الجديدة', 422);
            }
            $value = StockOrders::money($line['newValue'] ?? null);
            if (! BigDecimal::of($value)->isGreaterThan(0)) {
                throw new ApiError('قيمة الفئة الجديدة يجب أن تكون أكبر من صفر', 422);
            }
            $name = CatalogEditor::text($line['newName'] ?? '', 120);
            $code = ! empty($line['categoryCode']) ? ImportCategories::code($line['categoryCode']) : '';
            $reference = collect(json_decode(file_get_contents(database_path('seeders/asiacell-import-catalog.json')), true))->first(fn ($r) => in_array($code, $r['codes']));
            if ($reference && $reference['face'] !== null && (float) $value !== (float) $reference['face']) {
                throw new ApiError('قيمة الفئة لا تطابق معرّف ملف آسياسيل', 422);
            }
            $di = array_search((float) $value, $product['values']);
            if ($di === false) {
                if (count($product['values']) >= 100) {
                    throw new ApiError('الحد الأقصى 100 فئة للبطاقة', 422);
                }
                $di = count($product['values']);
                $product['values'][] = (float) $value;
                $product['prices'][] = 1;
                $product['stock'][] = 0;
                $product['denomImages'] = array_map(fn ($i) => $product['denomImages'][$i] ?? '', array_keys($product['values']));
                ProductFields::product($product, $product, $before, $state);
                $product['denomNames'][$di] = $name;
                $product['translations']['ar']['denomNames'][$di] = $name;
            }
        } else {
            $di = $line['denom'] ?? null;
            if (! is_int($di) || ! isset($product['values'][$di])) {
                throw new ApiError('اختر الفئة أو أدخل فئة جديدة', 422);
            }
        }
        $retail = StockOrders::money($line['retail'] ?? (string) $product['prices'][$di]);
        if (! BigDecimal::of($retail)->isGreaterThan(0)) {
            throw new ApiError('سعر البيع يجب أن يكون أكبر من صفر', 422);
        }
        if ($new && ! isset($line['retail'])) {
            throw new ApiError('أدخل سعر بيع الفئة الجديدة', 422);
        }
        $product['prices'][$di] = (float) $retail;
        $storedCodes = app(ImportCategories::class)->forProduct($product);
        $product['denomImportCodes'] = array_map(fn ($i) => $product['denomImportCodes'][$i] ?? $storedCodes[$i] ?? '', array_keys($product['values']));
        if (! empty($line['categoryCode'])) {
            $code = ImportCategories::code($line['categoryCode']);
            $codes = ProductFields::codes($product['denomImportCodes'][$di] ?? '');
            if (! in_array($code, $codes)) {
                $product['denomImportCodes'][$di] = implode(', ', [...$codes, $code]);
                ProductFields::codes($product['denomImportCodes'][$di]);
            }
        }
        if (request()->attributes->has('adminAccess') && $product !== $before) {
            app(AdminPolicy::class)->collection('products', $product, $state);
        }
        $state['products'][$index] = $product;

        return ['line' => [...$line, 'denom' => $di, 'manufacturerId' => $manufacturer], 'catalogRequest' => $request, 'catalogFingerprint' => $fingerprint, 'catalogChanged' => $product !== $before, 'newDenomination' => ! in_array($product['values'][$di], $before['values'])];
    }

    public function replay(array $summary, array &$state): void
    {
        $product = collect($state['products'])->first(fn ($p) => (string) $p['id'] === $summary['productId']);
        if (! $product || ! hash_equals($summary['catalogFingerprint'], $this->fingerprint($product))) {
            throw new ApiError('الفئات أو الأسعار أو الشركة تغيرت؛ أعد معاينة الطلبية', 409);
        }
        $plan = $this->prepare($summary['catalogRequest'], $state);
        $product = collect($state['products'])->first(fn ($p) => (string) $p['id'] === $summary['productId']);
        if ($plan['line']['denom'] !== $summary['denom'] || (float) $product['values'][$summary['denom']] !== (float) $summary['value'] || StockOrders::money((string) $product['prices'][$summary['denom']]) !== $summary['retail']) {
            throw new ApiError('تفاصيل فئة الطلبية تغيرت؛ أعد المعاينة', 409);
        }
    }
}
