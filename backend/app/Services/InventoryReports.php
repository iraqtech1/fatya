<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class InventoryReports
{
    public function filters(array $input): array
    {
        $out = [];
        foreach (['from', 'to', 'productId', 'manufacturerId', 'supplier', 'status', 'actor', 'channel', 'batchId', 'serial', 'denomination'] as $key) {
            $out[$key] = CatalogEditor::text($input[$key] ?? '', 256);
        }
        if ($out['denomination'] !== '' && ! preg_match('/^\d{1,16}(\.\d{1,3})?$/D', $out['denomination'])) {
            throw new ApiError('قيمة الفئة غير صحيحة', 422);
        }
        foreach (['from', 'to'] as $key) {
            if ($out[$key] !== '' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $out[$key]) || ! ($date = \DateTimeImmutable::createFromFormat('!Y-m-d', $out[$key])) || $date->format('Y-m-d') !== $out[$key])) {
                throw new ApiError('اكتب تاريخاً صحيحاً بصيغة YYYY-MM-DD', 422);
            }
        }
        if ($out['from'] && $out['to'] && $out['from'] > $out['to']) {
            throw new ApiError('تاريخ البداية بعد تاريخ النهاية', 422);
        }

        return $out;
    }

    private function boundary(string $day, bool $end = false): int
    {
        $d = CarbonImmutable::parse($day, 'Asia/Baghdad')->startOfDay();

        return ($end ? $d->addDay() : $d)->timestamp;
    }

    private function inPeriod(?int $timestamp, array $f): bool
    {
        if (! $f['from'] && ! $f['to']) {
            return true;
        }

        return $timestamp !== null && (! $f['from'] || $timestamp >= $this->boundary($f['from'])) && (! $f['to'] || $timestamp < $this->boundary($f['to'], true));
    }

    public function inventoryQuery(array $f)
    {
        $q = DB::table('inventory_cards');
        foreach (['productId' => 'product_id', 'serial' => 'serial', 'batchId' => 'batch_id', 'denomination' => 'denomination'] as $k => $column) {
            if ($f[$k] !== '') {
                $q->where($column, $f[$k]);
            }
        }
        if ($f['manufacturerId']) {
            $ids = collect(app(Store::class)->read()['products'])->filter(fn ($p) => (string) ($p['manufacturerId'] ?? '') === $f['manufacturerId'])->pluck('id')->map(fn ($v) => (string) $v)->all();
            $q->whereIn('product_id', $ids);
        }
        if ($f['supplier'] || $f['actor']) {
            $ids = DB::table('stock_batches')->get()->filter(function ($r) use ($f) {
                $s = json_decode($r->summary, true);

                return (! $f['supplier'] || ($s['supplier'] ?? '') === $f['supplier']) && (! $f['actor'] || ($s['actor'] ?? '') === $f['actor']);
            })->pluck('id')->all();
            $q->whereIn('batch_id', $ids);
        }
        if ($f['from']) {
            $q->where('imported_at', '>=', $this->boundary($f['from']));
        }
        if ($f['to']) {
            $q->where('imported_at', '<', $this->boundary($f['to'], true));
        }
        $today = now('Asia/Baghdad')->toDateString();
        switch ($f['status']) {
            case 'available':$q->whereNull('sold_order_id')->whereNull('reserved_order_id')->whereNull('withdrawal_id')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today));
                break;
            case 'reserved':$q->whereNull('sold_order_id')->whereNotNull('reserved_order_id');
                break;
            case 'delivered':$q->whereNotNull('sold_order_id');
                break;
            case 'withdrawn':$q->whereNotNull('withdrawal_id');
                break;
            case 'expired':$q->whereNull('sold_order_id')->whereNull('reserved_order_id')->whereNull('withdrawal_id')->where('expires_on', '<', $today);
                break;
            case '':break;
            default:throw new ApiError('حالة المخزون غير صحيحة', 422);
        }
        if ($f['channel']) {
            throw new ApiError('فلتر مصدر الويب/التطبيق يخص تقارير المبيعات والدفع والتنفيذ', 422);
        }

        return $q;
    }

    public static function state(object $c): string
    {
        return $c->sold_order_id ? 'delivered' : ($c->withdrawal_id ? 'withdrawn' : ($c->reserved_order_id ? 'reserved' : ($c->expires_on && $c->expires_on < now('Asia/Baghdad')->toDateString() ? 'expired' : 'available')));
    }

    public function read(array $b): array
    {
        $type = $b['type'] ?? 'inventory';
        $f = $this->filters($b['filters'] ?? []);
        if (! in_array($type, ['inventory', 'movements', 'procurement', 'sales', 'digital', 'payments', 'exports', 'audit'], true)) {
            throw new ApiError('التقرير غير موجود', 422);
        }
        $supported = match ($type) {
            'inventory','movements' => ['from', 'to', 'productId', 'manufacturerId', 'supplier', 'status', 'actor', 'batchId', 'serial', 'denomination'],'procurement' => ['from', 'to', 'productId', 'manufacturerId', 'supplier', 'status', 'actor', 'batchId', 'denomination'],'sales','digital','payments' => ['from', 'to', 'productId', 'manufacturerId', 'status', 'channel', 'denomination'],'exports' => ['from', 'to', 'status', 'actor'],'audit' => ['from', 'to', 'actor']
        };
        foreach ($f as $k => $v) {
            if ($v !== '' && ! in_array($k, $supported, true)) {
                throw new ApiError('هذا الفلتر لا ينطبق على نوع التقرير المحدد', 422);
            }
        }
        if ($type === 'movements' && $f['status'] !== '') {
            throw new ApiError('حركة الفترة تشمل جميع الحالات؛ استخدم تقرير المخزون لتصفية الحالة', 422);
        }
        $s = app(Store::class)->read();
        $products = collect($s['products'])->keyBy(fn ($p) => (string) $p['id']);
        $orders = collect($s['orders'])->keyBy('id');
        $rows = [];
        if ($type === 'inventory') {
            $q = $this->inventoryQuery($f);
            if ($q->count() > 50000) {
                throw new ApiError('ضيّق الفلاتر إلى 50000 بطاقة أو أقل للتقرير', 422);
            }
            foreach ($q->orderBy('id')->get(['id', 'product_id', 'denomination', 'serial', 'batch_id', 'unit_cost', 'expires_on', 'imported_at', 'source_line', 'source_row', 'sold_order_id', 'reserved_order_id', 'withdrawal_id']) as $c) {
                $p = $products[$c->product_id] ?? null;
                $index = $p ? array_search((float) $c->denomination, $p['values']) : false;
                $state = self::state($c);
                $rows[] = ['id' => $c->id, 'productId' => $c->product_id, 'name' => $p['name'] ?? '', 'value' => (string) $c->denomination, 'unit' => $p['unit'] ?? '', 'serial' => $c->serial ?? '', 'status' => $state, 'batchId' => $c->batch_id ?? '', 'sourceLine' => $c->source_line, 'sourceRow' => $c->source_row, 'date' => $c->imported_at ? CarbonImmutable::createFromTimestamp($c->imported_at)->setTimezone('Asia/Baghdad')->toDateTimeString() : null, 'expiry' => $c->expires_on, 'cost' => $c->unit_cost, 'retail' => $index === false ? null : StockOrders::money((string) $p['prices'][$index]), 'orderId' => $c->sold_order_id ?? $c->reserved_order_id];
            }
        } elseif ($type === 'movements') {
            $scope = [...$f, 'from' => '', 'to' => '', 'status' => ''];
            $q = $this->inventoryQuery($scope);
            if ($q->count() > 50000) {
                throw new ApiError('ضيّق نطاق حركة المخزون إلى 50000 بطاقة', 422);
            }
            $deliveries = DB::table('delivered_cards')->pluck('delivered_at', 'card_id');
            $withdrawals = DB::table('inventory_exports')->where('status', 'approved')->pluck('approved_at', 'id');
            $groups = [];
            $start = $f['from'] ? $this->boundary($f['from']) : null;
            $end = $f['to'] ? $this->boundary($f['to'], true) : null;
            foreach ($q->get(['id', 'product_id', 'denomination', 'imported_at', 'withdrawal_id', 'sold_order_id']) as $c) {
                $key = $c->product_id.':'.$c->denomination;
                $g = $groups[$key] ?? ['productId' => $c->product_id, 'name' => $products[$c->product_id]['name'] ?? '', 'value' => (string) $c->denomination, 'opening' => 0, 'incoming' => 0, 'delivered' => 0, 'withdrawn' => 0, 'ending' => 0, 'unknownOpening' => 0];
                $at = $c->imported_at;
                $sold = $deliveries[$c->id] ?? null;
                $withdrawn = $c->withdrawal_id ? ($withdrawals[$c->withdrawal_id] ?? null) : null;
                if (! $at) {
                    $g['unknownOpening']++;
                    $groups[$key] = $g;

                    continue;
                }
                if ($start && $at < $start && (! $sold || $sold >= $start) && (! $withdrawn || $withdrawn >= $start)) {
                    $g['opening']++;
                }
                if ((! $start || $at >= $start) && (! $end || $at < $end)) {
                    $g['incoming']++;
                }
                if ($sold && (! $start || $sold >= $start) && (! $end || $sold < $end)) {
                    $g['delivered']++;
                }
                if ($withdrawn && (! $start || $withdrawn >= $start) && (! $end || $withdrawn < $end)) {
                    $g['withdrawn']++;
                }
                if ((! $end || $at < $end) && (! $sold || ($end && $sold >= $end)) && (! $withdrawn || ($end && $withdrawn >= $end))) {
                    $g['ending']++;
                }
                $groups[$key] = $g;
            }
            $rows = array_values($groups);
        } elseif ($type === 'procurement') {
            // The submitted snapshot preserves original uploaded/rejected counts after review.
            $items = DB::table('stock_submissions')->get()->map(function ($r) {
                $v = json_decode($r->summary, true);

                return [...$v, 'id' => $r->id, 'status' => $r->status, 'timestamp' => (int) $r->created];
            })->all();
            $reviewedIds = array_filter(array_column($items, 'batchId'));
            foreach (DB::table('stock_batches')->get() as $r) {
                if (! in_array($r->id, $reviewedIds, true)) {
                    $items[] = [...json_decode($r->summary, true), 'id' => $r->id, 'status' => 'approved', 'timestamp' => (int) $r->created];
                }
            }
            foreach ($items as $v) {
                if (! $this->inPeriod($v['timestamp'], $f) || ($f['status'] && $v['status'] !== $f['status']) || ($f['supplier'] && ($v['supplier'] ?? '') !== $f['supplier']) || ($f['actor'] && ($v['actor'] ?? '') !== $f['actor']) || ($f['batchId'] && $v['id'] !== $f['batchId'])) {
                    continue;
                }
                foreach ($v['lines'] ?? [] as $l) {
                    $p = $products[$l['productId']] ?? null;
                    if (($f['productId'] && $l['productId'] !== $f['productId']) || ($f['denomination'] !== '' && (float) $l['value'] !== (float) $f['denomination']) || ($f['manufacturerId'] && ($p['manufacturerId'] ?? '') !== $f['manufacturerId'])) {
                        continue;
                    }
                    $rows[] = ['id' => $v['id'], 'status' => $v['status'], 'supplier' => $v['supplier'], 'source' => $v['source'], 'actor' => $v['actor'] ?? null, 'date' => CarbonImmutable::createFromTimestamp($v['timestamp'])->setTimezone('Asia/Baghdad')->toDateTimeString(), 'file' => $l['fileName'] ?? null, 'line' => $l['line'], 'productId' => $l['productId'], 'name' => $l['name'], 'value' => $l['value'], 'uploaded' => $l['uploaded'] ?? null, 'accepted' => $l['accepted'], 'rejected' => $l['rejected'] ?? null, 'added' => $v['status'] === 'approved' ? $l['accepted'] : 0, 'cost' => $l['cost'], 'costTotal' => $l['costTotal'], 'retail' => $l['retail']];
                }
            }
        } elseif (in_array($type, ['sales', 'digital', 'payments'], true)) {
            $fulfillments = DB::table('order_fulfillments')->get()->keyBy('order_id');
            $digital = DB::table('digital_executions')->get()->keyBy('order_id');
            $receipts = $type === 'payments' ? DB::table('payment_receipts')->orderByDesc('received_at')->get() : $orders;
            foreach ($receipts as $item) {
                $o = $type === 'payments' ? ($orders[$item->order_id] ?? null) : $item;
                if (! $o) {
                    continue;
                }$p = $products[(string) $o['productId']] ?? [];
                $d = $digital[$o['id']] ?? null;
                $a = $fulfillments[$o['id']] ?? null;
                $time = $type === 'payments' ? (int) $item->received_at : (isset($o['createdAt']) ? strtotime($o['createdAt']) : CarbonImmutable::parse($o['date'], 'Asia/Baghdad')->timestamp);
                if (! $this->inPeriod($time, $f) || ($f['productId'] && (string) $o['productId'] !== $f['productId']) || ($f['denomination'] !== '' && (float) $o['value'] !== (float) $f['denomination']) || ($f['manufacturerId'] && ($p['manufacturerId'] ?? '') !== $f['manufacturerId']) || ($f['channel'] && ($o['channel'] ?? 'unknown') !== $f['channel'])) {
                    continue;
                }
                $status = $type === 'payments' ? $item->outcome : ($type === 'digital' ? $d?->state : ($a?->state ?? 'unknown'));
                if (($type === 'digital' && ! $d) || ($f['status'] && $status !== $f['status'])) {
                    continue;
                }
                $row = ['id' => $o['id'], 'date' => CarbonImmutable::createFromTimestamp($time)->setTimezone('Asia/Baghdad')->toDateTimeString(), 'name' => $o['name'], 'productId' => (string) $o['productId'], 'value' => $o['value'], 'quantity' => $o['quantity'], 'customer' => $o['customer'], 'channel' => $o['channel'] ?? 'unknown', 'amount' => (string) $o['price'], 'currency' => 'IQD', 'paymentStatus' => $a?->payment_status ?? 'unknown', 'executionStatus' => $d?->state ?? ($a?->state ?? 'unknown'), 'deliveryStatus' => $a?->state ?? 'unknown', 'cost' => $o['costTotal'] ?? null, 'grossProfit' => $o['profitBeforeFees'] ?? null, 'paymentFee' => null, 'netProfit' => null];
                if ($type === 'digital') {
                    $row += ['provider' => $d->provider, 'quotedCost' => $d->quoted_cost, 'actualCost' => $d->actual_cost, 'reference' => $d->provider_reference, 'reason' => $d->reason, 'lastChecked' => date(DATE_ATOM, $d->updated)];
                }
                if ($type === 'payments') {
                    $row = [...$row, 'receiptId' => $item->id, 'provider' => $item->provider, 'reference' => $item->transaction_id, 'amount' => $item->amount, 'currency' => $item->currency, 'status' => $item->outcome, 'reason' => $item->reason];
                }
                $rows[] = $row;
            }
        } elseif ($type === 'exports') {
            foreach (DB::table('inventory_exports')->orderByDesc('created')->get() as $r) {
                if (! $this->inPeriod((int) $r->created, $f) || ($f['actor'] && $f['actor'] !== $r->actor) || ($f['status'] && $f['status'] !== $r->status)) {
                    continue;
                }$rows[] = ['id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'actor' => $r->actor, 'reason' => $r->reason, 'quantity' => (int) $r->quantity, 'date' => CarbonImmutable::createFromTimestamp($r->created)->setTimezone('Asia/Baghdad')->toDateTimeString()];
            }
        } else {
            $audit = DB::table('procurement_audit');
            $actor = request()->attributes->get('adminAccess');
            if ($actor && ! $actor['owner']) {
                $audit->where('actor', '!=', (string) config('fatya.admin_user'));
            }
            foreach ($audit->orderByDesc('created')->get() as $r) {
                if (! $this->inPeriod((int) $r->created, $f) || ($f['actor'] && $f['actor'] !== $r->actor)) {
                    continue;
                }$rows[] = ['id' => $r->id, 'actor' => $r->actor, 'action' => $r->action, 'subject' => $r->subject, 'details' => $r->details, 'date' => CarbonImmutable::createFromTimestamp($r->created)->setTimezone('Asia/Baghdad')->toDateTimeString()];
            }
        }
        if (count($rows) > 50000) {
            throw new ApiError('ضيّق فلاتر التقرير إلى 50000 نتيجة أو أقل', 422);
        }
        // Use a stable readable order in both the table and the full export.
        usort($rows, static function ($a, $b) {
            return strcmp($b['date'] ?? '', $a['date'] ?? '')
                ?: strcmp($a['name'] ?? '', $b['name'] ?? '')
                ?: (($a['value'] ?? 0) <=> ($b['value'] ?? 0))
                ?: strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        // These totals and exports use the same filtered rows before pagination.
        $totals = ['rows' => count($rows), 'unknownCost' => 0];
        $moneyKeys = match ($type) {
            'procurement' => ['costTotal'],'sales','digital' => ['amount', 'grossProfit'],'payments' => ['amount'],default => []
        };
        foreach ($moneyKeys as $key) {
            $sum = BigDecimal::zero()->toScale(3);
            $known = 0;
            foreach ($rows as $r) {
                if (isset($r[$key]) && is_numeric($r[$key])) {
                    $sum = $sum->plus((string) $r[$key]);
                    $known++;
                }
            }$totals[$key] = $known ? (string) $sum : null;
        }
        foreach ($rows as $r) {
            if (array_key_exists('cost', $r) && $r['cost'] === null) {
                $totals['unknownCost']++;
            }
        }
        if (in_array($type, ['sales', 'digital'], true)) {
            $delivered = array_values(array_filter($rows, fn ($r) => $r['deliveryStatus'] === 'delivered' && $r['paymentStatus'] === 'confirmed'));
            foreach (['deliveredAmount' => 'amount', 'knownDeliveredCost' => 'cost', 'deliveredGrossProfit' => 'grossProfit'] as $total => $field) {
                $sum = BigDecimal::zero()->toScale(3);
                $known = 0;
                foreach ($delivered as $row) {
                    if (isset($row[$field]) && is_numeric($row[$field])) {
                        $sum = $sum->plus((string) $row[$field]);
                        $known++;
                    }
                }
                $totals[$total] = $known || ! $delivered ? (string) $sum : null;
            }
            $totals['deliveredUnknownCost'] = count(array_filter($delivered, fn ($r) => $r['cost'] === null));
        }
        if ($type === 'payments') {
            $sum = BigDecimal::zero()->toScale(3);
            foreach ($rows as $row) {
                if (in_array($row['status'], ['confirmed', 'delivered', 'processing'], true) && $row['reason'] === null && $row['currency'] === 'IQD') {
                    $sum = $sum->plus((string) $row['amount']);
                }
            }
            $totals['confirmedAmount'] = (string) $sum;
        }
        foreach (['uploaded', 'accepted', 'rejected', 'added'] as $key) {
            if ($type === 'procurement') {
                $totals[$key] = array_sum(array_column($rows, $key));
            }
        }
        if ($type === 'movements') {
            foreach (['opening', 'incoming', 'delivered', 'withdrawn', 'ending', 'unknownOpening'] as $key) {
                $totals[$key] = array_sum(array_column($rows, $key));
            }
        }
        if ($type === 'inventory') {
            foreach (['available', 'reserved', 'delivered', 'withdrawn', 'expired'] as $key) {
                $totals[$key] = count(array_filter($rows, fn ($r) => $r['status'] === $key));
            }
            $cost = BigDecimal::zero()->toScale(3);
            $retail = BigDecimal::zero()->toScale(3);
            $unknown = 0;
            foreach ($rows as $r) {
                if ($r['status'] === 'available') {
                    if ($r['cost'] === null) {
                        $unknown++;
                    } else {
                        $cost = $cost->plus((string) $r['cost']);
                    }if ($r['retail'] !== null) {
                        $retail = $retail->plus((string) $r['retail']);
                    }
                }
            }
            $totals += ['availableCostKnown' => (string) $cost, 'availableUnknownCost' => $unknown, 'availableRetail' => (string) $retail];
        }
        $page = $b['page'] ?? 1;
        if (! is_int($page) || $page < 1) {
            throw new ApiError('رقم الصفحة غير صحيح', 422);
        }
        $page = min($page, max(1, (int) ceil(count($rows) / 50)));

        return ['type' => $type, 'filters' => $f, 'timezone' => 'Asia/Baghdad', 'totals' => $totals, 'page' => $page, 'pages' => max(1, (int) ceil(count($rows) / 50)), 'rows' => ($b['all'] ?? false) === true ? $rows : array_slice($rows, ($page - 1) * 50, 50)];
    }
}
