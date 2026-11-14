<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

class ApplicationDisplay
{
    public static function defaults(): array
    {
        return ['mode' => 'grid', 'columns' => 2, 'flatCompanies' => false];
    }

    public function preview(): array
    {
        return DB::transaction(function () {
            $state = app(Store::class)->readPublic(null);
            app(Inventory::class)->refreshStock($state);
            app(CustomerCatalog::class)->decorate($state);

            return ['revision' => $state['revision'], 'products' => $state['products'], 'categories' => $state['categories'], 'settings' => $state['settings']['catalogDisplay'] ?? ['default' => self::defaults(), 'categories' => []]];
        });
    }

    public function save(array $input): array
    {
        return DB::transaction(function () use ($input) {
            $store = app(Store::class);
            $state = $store->read(true);
            if (($input['revision'] ?? null) !== $state['revision']) {
                throw new ApiError('إعداد العرض تغير؛ حدّث الصفحة قبل الحفظ', 409);
            }
            $settings = $input['settings'] ?? null;
            if (! is_array($settings) || array_diff(array_keys($settings), ['default', 'categories']) || ! is_array($settings['categories'] ?? null) || count($settings['categories']) > 100) {
                throw new ApiError('إعداد عرض غير صحيح', 422);
            }
            $validate = function ($layout) {
                if (! is_array($layout) || array_diff(array_keys($layout), ['mode', 'columns', 'flatCompanies']) || ! in_array($layout['mode'] ?? null, ['grid', 'list', 'row', 'tabs'], true) || ! is_int($layout['columns'] ?? null) || $layout['columns'] < 1 || $layout['columns'] > 4 || ! is_bool($layout['flatCompanies'] ?? null)) {
                    throw new ApiError('اختر عرضاً وعدد أعمدة صحيحاً', 422);
                }

                return array_intersect_key($layout, self::defaults());
            };
            $saved = ['default' => $validate($settings['default'] ?? null), 'categories' => []];
            $known = array_map('strval', array_column($state['categories'], 'id'));
            foreach ($settings['categories'] as $id => $layout) {
                if (! in_array((string) $id, $known, true)) {
                    throw new ApiError('التصنيف المحدد غير موجود', 422);
                }
                $saved['categories'][(string) $id] = $validate($layout);
            }
            $before = $state['settings']['catalogDisplay'] ?? ['default' => self::defaults(), 'categories' => []];
            $state['settings']['catalogDisplay'] = $saved;
            $store->event($state, 'تحديث عرض الشركات والفئات في الويب والتطبيق');
            $store->save($state);
            ProcurementAudit::record('application.display_updated', null, ['before' => $before, 'after' => $saved, 'revision' => $state['revision']]);

            return ['revision' => $state['revision'], 'settings' => $saved];
        });
    }
}
