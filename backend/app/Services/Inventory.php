<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Inventory
{
    public function summary(): array
    {
        return DB::table('inventory_cards')->selectRaw('product_id, denomination, COUNT(*) AS total, SUM(CASE WHEN sold_order_id IS NOT NULL THEN 1 ELSE 0 END) AS delivered, SUM(CASE WHEN sold_order_id IS NULL AND reserved_order_id IS NOT NULL THEN 1 ELSE 0 END) AS reserved, SUM(CASE WHEN sold_order_id IS NULL AND reserved_order_id IS NULL THEN 1 ELSE 0 END) AS available')->groupBy('product_id', 'denomination')->get()->map(fn ($r) => ['productId' => $r->product_id, 'value' => (float) $r->denomination, 'total' => (int) $r->total, 'delivered' => (int) $r->delivered, 'reserved' => (int) $r->reserved, 'available' => (int) $r->available])->all();
    }

    public function refreshStock(array &$s): void
    {
        $rows = DB::table('inventory_cards')->whereNull('reserved_order_id')->whereNull('sold_order_id')->selectRaw('product_id, denomination, COUNT(*) AS available')->groupBy('product_id', 'denomination')->get();
        foreach ($s['products'] as &$p) {
            $p['stock'] = array_map(fn ($value) => (int) ($rows->first(fn ($r) => $r->product_id === (string) $p['id'] && (float) $r->denomination === (float) $value)->available ?? 0), $p['values']);
        }
    }

    public function search(array $s, mixed $serial): ?array
    {
        $key = CatalogEditor::text($serial, 256);
        if (! $key) {
            throw new ApiError('اكتب سيريل الكارت');
        }
        $row = DB::table('inventory_cards')->where('serial', $key)->first()
            ?? DB::table('inventory_cards')->whereNull('serial')->where('code_hash', hash('sha256', $key))->first();
        $p = $row ? collect($s['products'])->first(fn ($p) => (string) $p['id'] === $row->product_id) : null;
        if (! $p) {
            return null;
        }

        $orderId = $row->sold_order_id ?? $row->reserved_order_id;
        $fulfillment = $orderId ? DB::table('order_fulfillments')->where('order_id', $orderId)->first() : null;

        return ['productId' => $p['id'], 'name' => $p['name'], 'value' => (float) $row->denomination, 'unit' => $p['unit'], 'serial' => $row->serial ?? $key, 'inventoryStatus' => $row->sold_order_id ? 'delivered' : ($row->reserved_order_id ? 'reserved' : 'available'), 'orderId' => $orderId, 'reservationExpiresAt' => $row->reserved_order_id && $fulfillment ? $fulfillment->expires * 1000 : null];
    }

    public function import(array &$s, array $b): array
    {
        $rows = $b['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 5000) {
            throw new ApiError('الحد الأقصى 5000 صف');
        }
        $name = CatalogEditor::text($b['name'] ?? null, 200);
        $seen = array_fill_keys(DB::table('inventory_cards')->pluck('code_hash')->all(), true);
        $serials = array_fill_keys(DB::table('inventory_cards')->whereNotNull('serial')->pluck('serial')->all(), true);
        $accepted = [];
        $errors = [];
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                $errors[] = ['row' => $i + 2, 'reason' => 'بيانات غير صحيحة'];

                continue;
            }
            $pid = is_numeric($row['product_id'] ?? null) ? (float) $row['product_id'] : null;
            $value = is_numeric($row['denomination'] ?? null) ? (float) $row['denomination'] : null;
            $code = is_scalar($row['code'] ?? '') ? trim((string) ($row['code'] ?? '')) : '';
            $serial = is_scalar($row['serial'] ?? '') ? trim((string) ($row['serial'] ?? '')) : '';
            $hash = hash('sha256', $code);
            $pindex = null;
            $index = false;
            foreach ($s['products'] as $j => $p) {
                if ($p['id'] == $pid && $pid !== null) {
                    $pindex = $j;
                    $index = array_search($value, $p['values']);
                    break;
                }
            }
            $reason = '';
            if ($pindex === null || $index === false || $value === null) {
                $reason = 'بطاقة أو فئة غير موجودة';
            } elseif (! $code || mb_strlen($code) > 256) {
                $reason = 'الكود فارغ أو أطول من المسموح';
            } elseif (isset($seen[$hash])) {
                $reason = 'كود مكرر';
            } elseif (mb_strlen($serial) > 256) {
                $reason = 'السيريل أطول من المسموح';
            } elseif ($serial !== '' && isset($serials[$serial])) {
                $reason = 'سيريل مكرر';
            }
            if ($reason) {
                $errors[] = ['row' => $i + 2, 'reason' => $reason];

                continue;
            }
            $seen[$hash] = true;
            if ($serial !== '') {
                $serials[$serial] = true;
            }
            $accepted[] = ['product_id' => (string) $s['products'][$pindex]['id'], 'denomination' => $value, 'code_hash' => $hash, 'encrypted_code' => Crypt::encryptString($code), 'serial' => $serial === '' ? null : $serial];
            $s['products'][$pindex]['stock'][$index]++;
        }
        foreach (array_chunk($accepted, 100) as $chunk) {
            DB::table('inventory_cards')->insert($chunk);
        }
        array_unshift($s['imports'], ['id' => (string) Str::uuid(), 'name' => $name, 'count' => count($accepted), 'rejected' => count($errors), 'date' => now('Asia/Baghdad')->toDateString()]);

        return ['accepted' => count($accepted), 'errors' => $errors];
    }
}
