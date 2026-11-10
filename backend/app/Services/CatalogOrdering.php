<?php

namespace App\Services;

use App\Exceptions\ApiError;

class CatalogOrdering
{
    public function moveDenomination(array &$state, mixed $id, mixed $denom, mixed $direction): bool
    {
        app(AdminAccess::class)->require('products.update');
        if ((! is_int($id) && ! is_string($id)) || ! is_int($denom) || ! in_array($direction, [-1, 1], true)) {
            throw new ApiError('اختر فئة واتجاه ترتيب صحيحاً', 422);
        }
        $index = collect($state['products'])->search(fn ($p) => (string) $p['id'] === (string) $id);
        if ($index === false || ! array_key_exists($denom, $state['products'][$index]['values'])) {
            throw new ApiError('الفئة غير موجودة', 404);
        }
        $product = &$state['products'][$index];
        $indices = array_keys($product['values']);
        $order = fn ($i) => $product['denomOrder'][$i] ?? (($i + 1) * 10);
        usort($indices, fn ($a, $b) => $order($a) <=> $order($b));
        $position = array_search($denom, $indices, true);
        $to = $position + $direction;
        if ($to < 0 || $to >= count($indices)) {
            return false;
        }
        [$indices[$position], $indices[$to]] = [$indices[$to], $indices[$position]];
        $orders = [];
        foreach ($indices as $i => $di) {
            $orders[$di] = ($i + 1) * 10;
        }
        ksort($orders);
        $product['denomOrder'] = array_values($orders);

        return true;
    }

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
