<?php

namespace App\Services;

use App\Exceptions\ApiError;

class ProductFields
{
    public static function policy(array $product, ?int $denom = null): array
    {
        return ['pin' => true, 'expiry' => true, 'serial' => false, 'cvc' => false, 'reference' => false, ...($product['importFields'] ?? []), ...($denom !== null ? ($product['denomImportFields'][$denom] ?? []) : [])];
    }

    public static function codes(mixed $text): array
    {
        $text = CatalogEditor::text($text, 500);
        $codes = array_values(array_unique(array_map(ImportCategories::code(...), preg_split('/[\s,،;]+/u', $text, -1, PREG_SPLIT_NO_EMPTY))));
        if (count($codes) > 10) {
            throw new ApiError('الحد الأقصى عشرة معرّفات لكل فئة', 422);
        }

        return $codes;
    }

    public static function order(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 999999) {
            throw new ApiError('ترتيب الظهور يجب أن يكون عدداً بين 0 و999999', 422);
        }

        return $value;
    }

    private static function list(mixed $value, int $count, string $field, int $max, array $defaults = []): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > $count) {
            throw new ApiError('بيانات '.$field.' غير صحيحة', 422);
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = CatalogEditor::text($value[$i] ?? $defaults[$i] ?? '', $max);
        }

        return $out;
    }

    public static function translations(array $item, array $data, ?array $old, bool $category = false): array
    {
        $count = $category ? 0 : count($item['values']);
        $input = $data['translations'] ?? $old['translations'] ?? [];
        if (! is_array($input) || array_diff(array_keys($input), ['ar', 'ku', 'en'])) {
            throw new ApiError('لغات البطاقة غير صحيحة', 422);
        }
        $translations = [];
        foreach (['ar', 'ku', 'en'] as $locale) {
            $row = $input[$locale] ?? [];
            if (! is_array($row)) {
                throw new ApiError('بيانات اللغة غير صحيحة', 422);
            }
            $ar = $locale === 'ar';
            $r = ['name' => CatalogEditor::text($row['name'] ?? ($ar ? $item['name'] : ''), $category ? 40 : ($ar ? 60 : 80)), 'description' => CatalogEditor::text($row['description'] ?? ($ar ? ($item['tag'] ?? '') : ''), 1000), 'image' => $row['image'] ?? ($ar ? ($item['image'] ?? '') : '')];
            CatalogEditor::image($r['image']);
            if ($ar) {
                $r['name'] = $item['name'];
                $r['image'] = $item['image'] ?? '';
                if (! $category && mb_substr($r['description'], 0, 40) !== ($item['tag'] ?? '')) {
                    $r['description'] = $item['tag'] ?? '';
                }
            }
            if ($ar && ! $r['name']) {
                throw new ApiError('اكتب الاسم بالعربية', 422);
            }
            if (! $category) {
                $r['denomNames'] = self::list($row['denomNames'] ?? [], $count, 'أسماء الفئات', 120, $ar ? ($item['denomNames'] ?? []) : []);
                $r['denomDescriptions'] = self::list($row['denomDescriptions'] ?? [], $count, 'أوصاف الفئات', 1000, $ar ? ($item['denomDescriptions'] ?? []) : []);
                $r['denomImages'] = self::list($row['denomImages'] ?? [], $count, 'صور الفئات', 3000000, $ar ? ($item['denomImages'] ?? []) : []);
                if ($ar) {
                    foreach (['denomNames', 'denomDescriptions', 'denomImages'] as $field) {
                        $r[$field] = $item[$field];
                    }
                }
                foreach ($r['denomImages'] as $image) {
                    CatalogEditor::image($image);
                }
            }
            $translations[$locale] = $r;
        }

        return $translations;
    }

    public static function product(array &$item, array $data, ?array $old, array $state): void
    {
        $count = count($item['values']);
        // Older clients only resize the established arrays. Rebase untouched
        // metadata by denomination value instead of dropping translations or IDs.
        if ($old && $item['values'] !== $old['values']) {
            $remap = static function (array $values) use ($item, $old): array {
                return array_map(static function ($value) use ($old, $values) {
                    $i = array_search($value, $old['values']);

                    return $i === false ? '' : ($values[$i] ?? '');
                }, $item['values']);
            };
            foreach (['denomNames', 'denomDescriptions', 'denomImportCodes', 'denomOrder'] as $field) {
                $before = $field === 'denomImportCodes' ? app(ImportCategories::class)->forProduct($old) : ($old[$field] ?? []);
                if (! array_key_exists($field, $data) || $data[$field] === $before || $data[$field] === ($old[$field] ?? [])) {
                    $data[$field] = $remap($before);
                    if ($field === 'denomOrder') {
                        foreach ($data[$field] as $i => &$value) {
                            if ($value === '') {
                                $value = ($i + 1) * 10;
                            }
                        }
                    } unset($value);
                }
            }
            $beforeRules = $old['denomImportFields'] ?? [];
            if (! array_key_exists('denomImportFields', $data) || $data['denomImportFields'] === $beforeRules) {
                $data['denomImportFields'] = array_map(fn ($r) => $r === '' ? null : $r, $remap($beforeRules));
            }
            $translations = $data['translations'] ?? $old['translations'] ?? [];
            if (! is_array($translations)) {
                throw new ApiError('لغات البطاقة غير صحيحة', 422);
            }
            foreach (['ar', 'ku', 'en'] as $locale) {
                if (isset($translations[$locale]) && ! is_array($translations[$locale])) {
                    throw new ApiError('بيانات اللغة غير صحيحة', 422);
                }
                foreach (['denomNames', 'denomDescriptions', 'denomImages'] as $field) {
                    $before = $old['translations'][$locale][$field] ?? [];
                    if (! isset($translations[$locale][$field]) || $translations[$locale][$field] === $before) {
                        $translations[$locale][$field] = $remap($before);
                    }
                }
            }
            $data['translations'] = $translations;
        }
        $position = $old ? array_search($old['id'], array_column($state['products'], 'id'), true) : count($state['products']);
        $item['displayOrder'] = self::order($data['displayOrder'] ?? $old['displayOrder'] ?? (($position + 1) * 10));
        $policy = $data['importFields'] ?? self::policy($old ?? []);
        if (! is_array($policy) || array_diff(array_keys($policy), ['pin', 'expiry', 'serial', 'cvc', 'reference'])) {
            throw new ApiError('حقول الاستيراد غير صحيحة', 422);
        }
        foreach (self::policy([]) as $key => $default) {
            if (array_key_exists($key, $policy) && ! is_bool($policy[$key])) {
                throw new ApiError('اختيار حقل الاستيراد غير صحيح', 422);
            }
        }
        if (($policy['pin'] ?? true) !== true) {
            throw new ApiError('PIN مطلوب دائماً', 422);
        }
        $item['importFields'] = [...self::policy([]), ...$policy];
        $rules = $data['denomImportFields'] ?? $old['denomImportFields'] ?? [];
        if (! is_array($rules) || ! array_is_list($rules) || count($rules) > $count) {
            throw new ApiError('سياسة حقول الفئات غير صحيحة', 422);
        }
        $item['denomImportFields'] = [];
        foreach (array_keys($item['values']) as $i) {
            $row = $rules[$i] ?? null;
            if ($row !== null) {
                if (! is_array($row) || array_diff(array_keys($row), array_keys(self::policy([]))) || ($row['pin'] ?? true) !== true) {
                    throw new ApiError('سياسة حقول الفئة غير صحيحة؛ PIN مطلوب دائماً', 422);
                }
                foreach ($row as $v) {
                    if (! is_bool($v)) {
                        throw new ApiError('اختيار حقل الفئة غير صحيح', 422);
                    }
                }
            }
            $item['denomImportFields'][] = $row;
        }
        foreach (['denomNames' => 120, 'denomDescriptions' => 1000] as $field => $max) {
            $item[$field] = self::list($data[$field] ?? $old[$field] ?? [], $count, $field, $max);
        }
        $orders = $data['denomOrder'] ?? $old['denomOrder'] ?? [];
        if (! is_array($orders) || ! array_is_list($orders) || count($orders) > $count) {
            throw new ApiError('ترتيب الفئات غير صحيح', 422);
        }
        $item['denomOrder'] = array_map(fn ($i) => self::order($orders[$i] ?? (($i + 1) * 10)), array_keys($item['values']));
        $codes = $data['denomImportCodes'] ?? ($old ? app(ImportCategories::class)->forProduct($old) : []);
        $item['denomImportCodes'] = array_map(fn ($v) => implode(', ', self::codes($v)), self::list($codes, $count, 'معرّفات الفئات', 500));
        $item['translations'] = self::translations($item, $data, $old);
        $item['name'] = $item['translations']['ar']['name'];
        $item['image'] = $item['translations']['ar']['image'];
        $item['denomNames'] = $item['translations']['ar']['denomNames'];
        $item['denomDescriptions'] = $item['translations']['ar']['denomDescriptions'];
        $item['denomImages'] = $item['translations']['ar']['denomImages'];
    }

    public static function sort(array $products): array
    {
        foreach ($products as $i => &$product) {
            $product['displayOrder'] ??= ($i + 1) * 10;
        } unset($product);
        usort($products, fn ($a, $b) => $a['displayOrder'] <=> $b['displayOrder']);

        return $products;
    }
}
