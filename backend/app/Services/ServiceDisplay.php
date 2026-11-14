<?php

namespace App\Services;

use App\Exceptions\ApiError;

/** Local presentation overrides travel with a publication, never with the provider identity. */
class ServiceDisplay
{
    public static function validate(mixed $input): array
    {
        if (! is_array($input) || array_diff(array_keys($input), ['translations', 'order']) || ! is_int($input['order'] ?? 0) || ($input['order'] ?? 0) < 0 || ($input['order'] ?? 0) > 999999 || ! is_array($input['translations'] ?? [])) {
            throw new ApiError('بيانات عرض الخدمة غير صحيحة', 422);
        }
        $result = ['order' => $input['order'] ?? 0, 'translations' => []];
        if (array_diff(array_keys($input['translations'] ?? []), ['ar', 'ku', 'en'])) {
            throw new ApiError('لغة الخدمة غير صحيحة', 422);
        }
        foreach (['ar', 'ku', 'en'] as $locale) {
            $row = $input['translations'][$locale] ?? [];
            if (! is_array($row) || array_diff(array_keys($row), ['title', 'description', 'image'])) {
                throw new ApiError('حقول عرض الخدمة غير صحيحة', 422);
            }
            $image = CatalogEditor::text($row['image'] ?? '', 2048);
            CatalogEditor::image($image);
            $result['translations'][$locale] = ['title' => CatalogEditor::text($row['title'] ?? '', 200), 'description' => CatalogEditor::text($row['description'] ?? '', 2000), 'image' => $image];
        }

        return $result;
    }

    public static function fields(array $technical, array $saved): array
    {
        $display = $saved['display'] ?? [];
        $ar = $display['translations']['ar'] ?? [];

        return ['title' => ($ar['title'] ?? '') ?: $technical['title'], 'description' => ($ar['description'] ?? '') ?: ($technical['description'] ?? ''), 'images' => ! empty($ar['image']) ? [$ar['image']] : ($technical['images'] ?? []), 'translations' => $display['translations'] ?? [], 'displayOrder' => $display['order'] ?? 0];
    }
}
