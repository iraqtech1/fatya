<?php

namespace App\Services;

use App\Exceptions\ApiError;

class CompanyHierarchy
{
    public static function fields(array $item, array $data, ?array $old, array $companies): array
    {
        $parentId = $data['parentId'] ?? $old['parentId'] ?? '';
        if (! is_string($parentId)) {
            throw new ApiError('اختر الشركة أو الفرع الأب', 422);
        }
        $seen = [$item['id']];
        $cursor = $parentId;
        while ($cursor !== '') {
            if (in_array($cursor, $seen, true) || count($seen) >= 20) {
                throw new ApiError('لا يمكن ربط الفرع بنفسه أو بأحد أبنائه؛ الحد الأقصى 20 مستوى', 422);
            }
            $seen[] = $cursor;
            $parent = collect($companies)->firstWhere('id', $cursor);
            if (! $parent || ! in_array($parent['role'], ['manufacturer', 'both'], true) || ! in_array($item['role'], ['manufacturer', 'both'], true)) {
                throw new ApiError('الأب يجب أن يكون شركة مصنّعة أو فرعاً تابعاً لها', 422);
            }
            $cursor = $parent['parentId'] ?? '';
        }
        if (! in_array($item['role'], ['manufacturer', 'both'], true) && collect($companies)->contains(fn ($company) => ($company['parentId'] ?? '') === $item['id'])) {
            throw new ApiError('هذه الشركة لها فروع؛ لا يمكن تحويلها إلى مصدر فقط', 422);
        }
        $order = $data['displayOrder'] ?? $old['displayOrder'] ?? 0;
        if (! is_int($order) || $order < 0 || $order > 999999) {
            throw new ApiError('ترتيب الشركة يجب أن يكون رقماً صحيحاً', 422);
        }
        $input = $data['translations'] ?? $old['translations'] ?? [];
        if (! is_array($input) || array_diff(array_keys($input), ['ar', 'ku', 'en'])) {
            throw new ApiError('لغات الشركة غير صحيحة', 422);
        }
        $translations = [];
        foreach (['ar', 'ku', 'en'] as $locale) {
            $row = $input[$locale] ?? [];
            if (! is_array($row)) {
                throw new ApiError('بيانات لغة الشركة غير صحيحة', 422);
            }
            $image = $locale === 'ar' ? $item['image'] : ($row['image'] ?? '');
            CatalogEditor::image($image);
            $translations[$locale] = ['name' => $locale === 'ar' ? $item['name'] : CatalogEditor::text($row['name'] ?? '', 80), 'description' => CatalogEditor::text($row['description'] ?? '', 1000), 'image' => $image];
        }

        return ['parentId' => $parentId, 'displayOrder' => $order, 'translations' => $translations];
    }

    public static function path(array $companies, string $id): array
    {
        $path = [];
        $seen = [];
        while ($id !== '') {
            $company = collect($companies)->firstWhere('id', $id);
            if (! $company || in_array($id, $seen, true) || count($seen) >= 20) {
                return [];
            }
            $seen[] = $id;
            array_unshift($path, array_intersect_key($company, array_flip(['id', 'name', 'image', 'active', 'parentId', 'translations', 'displayOrder'])));
            $id = $company['parentId'] ?? '';
        }

        return $path;
    }
}
