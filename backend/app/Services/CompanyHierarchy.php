<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

class CompanyHierarchy
{
    public static function reorder(array &$companies, array &$item): void
    {
        $siblings = array_values(array_filter($companies, fn ($company) => $company['id'] !== $item['id'] && ($company['parentId'] ?? '') === ($item['parentId'] ?? '') && in_array($company['role'], ['manufacturer', 'both'], true) === in_array($item['role'], ['manufacturer', 'both'], true)));
        usort($siblings, fn ($a, $b) => ($a['displayOrder'] ?? 0) <=> ($b['displayOrder'] ?? 0));
        $position = min($item['displayOrder'], count($siblings));
        array_splice($siblings, $position, 0, [$item]);
        foreach ($siblings as $index => $sibling) {
            if ($sibling['id'] === $item['id']) {
                $item['displayOrder'] = $index;
            }
            foreach ($companies as &$company) {
                if ($company['id'] === $sibling['id']) {
                    $company['displayOrder'] = $index;
                    break;
                }
            }unset($company);
        }
    }

    public function move(array $input): array
    {
        if (! is_string($input['id'] ?? null) || ! in_array($input['direction'] ?? null, [-1, 1], true)) {
            throw new ApiError('اختر شركة واتجاهاً صحيحاً', 422);
        }

        return DB::transaction(function () use ($input) {
            $store = app(Store::class);
            $state = $store->read(true);
            if (($input['revision'] ?? null) !== $state['revision']) {
                throw new ApiError('الترتيب تغير؛ حدّث الصفحة', 409);
            }
            $item = collect($state['companies'])->firstWhere('id', $input['id']);
            if (! $item) {
                throw new ApiError('الشركة غير موجودة', 404);
            }
            $siblings = array_values(array_filter($state['companies'], fn ($company) => ($company['parentId'] ?? '') === ($item['parentId'] ?? '') && in_array($company['role'], ['manufacturer', 'both'], true) === in_array($item['role'], ['manufacturer', 'both'], true)));
            usort($siblings, fn ($a, $b) => ($a['displayOrder'] ?? 0) <=> ($b['displayOrder'] ?? 0));
            $position = array_search($item['id'], array_column($siblings, 'id'), true);
            $target = $position + $input['direction'];
            if ($target < 0 || $target >= count($siblings)) {
                return ['revision' => $state['revision'], 'changed' => false];
            }
            $item['displayOrder'] = $target;
            self::reorder($state['companies'], $item);
            $store->event($state, 'تحديث ترتيب الشركة: '.$item['name']);
            $store->save($state);
            ProcurementAudit::record('company.reordered', $item['id'], ['from' => $position, 'to' => $target, 'parentId' => $item['parentId'] ?? '', 'revision' => $state['revision']]);

            return ['revision' => $state['revision'], 'changed' => true];
        });
    }

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
