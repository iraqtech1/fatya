<?php

namespace App\Services;

use App\Exceptions\ApiError;

class CatalogOrdering
{
    public function move(array &$state, mixed $id, mixed $direction): bool
    {
        app(AdminAccess::class)->require('products.update');
        if ((! is_int($id) && ! is_string($id)) || ! in_array($direction, [-1, 1], true)) {
            throw new ApiError('اختر بطاقة واتجاه ترتيب صحيحاً', 422);
        }
        $rows = ProductFields::sort($state['products']);
        $index = collect($rows)->search(fn ($p) => (string) $p['id'] === (string) $id);
        if ($index === false) {
            throw new ApiError('البطاقة غير موجودة', 404);
        }
        $to = $index + $direction;
        if ($to < 0 || $to >= count($rows)) {
            return false;
        }
        [$rows[$index], $rows[$to]] = [$rows[$to], $rows[$index]];
        foreach ($rows as $i => &$row) {
            $row['displayOrder'] = ($i + 1) * 10;
        }
        unset($row);
        $state['products'] = $rows;

        return true;
    }
}
