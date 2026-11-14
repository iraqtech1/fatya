<?php

namespace App\Services\Captain;

use Illuminate\Validation\ValidationException;

class MasalCatalog
{
    public function normalize(array $products): array
    {
        abort_unless(array_is_list($products) && count($products) <= 5000, 422, 'قائمة الشركة لا تطابق عقد الربط.');
        $catalog = [];
        $keys = [];
        $excluded = [];
        $parents = [];
        foreach ($products as $row) {
            if (! is_array($row) || (isset($row['show2site']) && (int) $row['show2site'] !== 1) || ! in_array($row['type'] ?? null, ['topup', 'bundle', 'bill'], true)) {
                continue;
            }
            $id = $this->identifier(array_key_exists('id', $row) ? $row['id'] : ($row['product_id'] ?? null));
            if ($id !== null) {
                $key = $row['type'].':'.$id;
                $parents[$key] = array_key_exists($key, $parents) ? null : $row;
            }
        }
        foreach ($products as $row) {
            abort_unless(is_array($row), 422, 'سجل فئة الشركة غير صحيح.');
            if ((isset($row['show2site']) && (int) $row['show2site'] !== 1) || ! in_array($row['type'] ?? null, ['topup', 'bundle', 'bill'], true)) {
                continue;
            }
            if (($row['price'] ?? null) === null) {
                $excluded[] = $this->excludedRow($row, 'missing_price', 'الشركة لم ترسل سعرًا لهذا السجل.');

                continue;
            }
            $cost = DigitalMoney::minor($row['price'], 'cost', true);
            if ($cost === 0) {
                $excluded[] = $this->excludedRow($row, 'zero_price', 'السعر صفر؛ السجل غير صالح للبيع وقد يكون عنوان مجموعة.');

                continue;
            }
            DigitalMoney::minor($row['price'], 'cost');
            abort_unless(is_string($row['title'] ?? null) && trim($row['title']) !== '' && mb_strlen($row['title']) <= 200, 422, 'اسم فئة الشركة غير صحيح.');
            $remote = array_key_exists('id', $row) ? $row['id'] : ($row['product_id'] ?? null);
            $remote = is_int($remote) ? (string) $remote : $remote;
            abort_unless(is_string($remote) && strlen($remote) >= 1 && strlen($remote) <= 100, 422, 'معرف فئة الشركة غير صحيح.');
            abort_if(isset($row['currency']) && $row['currency'] !== 'IQD', 422, 'عملة فئة الشركة لا تطابق الدينار العراقي.');
            $key = hash('sha256', $row['type'].':'.$remote);
            if (isset($keys[$key])) {
                throw ValidationException::withMessages(['gateway' => 'معرف فئة الشركة مكرر؛ لم يتم استبدال الفئات الحالية.']);
            }
            $keys[$key] = true;
            $catalog[] = ['key' => $key, 'remote_id' => $remote, 'remote_name' => trim($row['title']), 'province_id' => '', 'province' => '', 'package_type' => 'standard', 'type' => $row['type'], 'cost' => Money::decimal($cost), 'retail' => Money::decimal($cost), 'description' => is_string($row['description'] ?? null) ? mb_substr(trim($row['description']), 0, 2000) : null, 'group_path' => $this->groupPath($row, $parents, $remote)];
        }

        return ['catalog' => $catalog, 'excluded_count' => count($excluded), 'excluded_catalog' => $excluded];
    }

    private function identifier(mixed $value): ?string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : null;
        }

        return is_string($value) && strlen($value) >= 1 && strlen($value) <= 100 && trim($value) !== '' ? $value : null;
    }

    private function groupPath(array $row, array $parents, string $remote): array
    {
        if (! isset($parents[$row['type'].':'.$remote])) {
            return [];
        }
        $path = [];
        $seen = [$row['type'].':'.$remote => true];
        for ($depth = 0; $depth <= 32; $depth++) {
            $value = $row['parent_id'] ?? null;
            if (in_array($value, [null, '', 0, '0'], true)) {
                return array_reverse($path);
            }
            $id = $this->identifier($value);
            $parent = $id === null ? null : ($parents[$row['type'].':'.$id] ?? null);
            if ($depth === 32 || ! $parent || isset($seen[$row['type'].':'.$id]) || $parent['type'] !== $row['type'] || ! is_string($parent['title'] ?? null) || trim($parent['title']) === '' || mb_strlen($parent['title']) > 200) {
                return [];
            }
            $seen[$row['type'].':'.$id] = true;
            $path[] = ['remote_id' => $id, 'name' => trim($parent['title'])];
            $row = $parent;
        }

        return [];
    }

    private function excludedRow(array $row, string $code, string $reason): array
    {
        $remote = $row['id'] ?? $row['product_id'] ?? null;

        return ['remote_id' => is_scalar($remote) ? mb_substr((string) $remote, 0, 100) : null, 'remote_name' => is_string($row['title'] ?? null) ? mb_substr(trim($row['title']), 0, 200) : 'سجل دون اسم', 'type' => $row['type'], 'price' => $code === 'zero_price' ? '0.00' : null, 'reason_code' => $code, 'reason' => $reason];
    }
}
