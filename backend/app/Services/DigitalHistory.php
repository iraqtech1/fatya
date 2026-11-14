<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** One filtered snapshot supplies rows, totals and the export dataset. */
class DigitalHistory
{
    public static function status(string $state): string
    {
        return match ($state) {
            'delivered' => 'succeeded', 'failed', 'cancelled', 'expired' => 'failed', 'review' => 'review', 'refunded' => 'refunded', default => 'pending'
        };
    }

    public function read(array $input, ?string $fixedProvider = null, bool $profit = false): array
    {
        $provider = $fixedProvider ?? ($input['provider'] ?? '');
        $status = $input['status'] ?? '';
        $query = $input['q'] ?? '';
        $page = $input['page'] ?? 1;
        $size = $input['per_page'] ?? 25;
        if (! in_array($provider, ['', 'topup', 'rabiaa'], true) || ! in_array($status, ['', 'pending', 'succeeded', 'failed', 'review', 'refunded'], true) || ! is_string($query) || mb_strlen($query) > 160 || ! is_int($page) || $page < 1 || ! in_array($size, [25, 50, 100], true)) {
            throw new ApiError('فلاتر سجل العمليات غير صحيحة', 422);
        }
        if (isset($input['product_id']) && ! is_string($input['product_id']) && ! is_int($input['product_id'])) {
            throw new ApiError('الفئة غير صحيحة', 422);
        }
        foreach (['account_id', 'type', 'group_id'] as $key) {
            if (isset($input[$key]) && (! is_string($input[$key]) || strlen($input[$key]) > 160)) {
                throw new ApiError('فلتر غير صحيح', 422);
            }
        }
        if (! in_array($input['type'] ?? '', ['', 'topup', 'bundle', 'bill', 'standard', 'premium'], true)) {
            throw new ApiError('نوع الخدمة غير صحيح', 422);
        }
        $dates = app(InventoryReports::class)->filters(['from' => $input['from'] ?? '', 'to' => $input['to'] ?? '']);
        $from = $dates['from'] !== '' ? app(InventoryReports::class)->boundary($dates['from']) : null;
        $to = $dates['to'] !== '' ? app(InventoryReports::class)->boundary($dates['to'], true) : null;

        return DB::transaction(function () use ($input, $provider, $status, $query, $page, $size, $from, $to, $profit) {
            $state = app(Store::class)->read();
            $orders = collect($state['orders'])->keyBy('id');
            $payments = DB::table('order_fulfillments')->get()->keyBy('order_id');
            $refundTotals = DigitalRefunds::totals();
            $dispatches = DigitalDispatches::forOrders(DB::table('digital_executions')->pluck('order_id')->all());
            $rows = [];
            $options = ['products' => [], 'accounts' => []];
            $executions = DB::table('digital_executions')->when($provider !== '', fn ($q) => $q->where('provider', $provider))->orderByDesc('updated')->get();
            foreach ($executions as $execution) {
                $order = $orders->get($execution->order_id);
                if (! $order) {
                    continue;
                }
                $options['products'][(string) $order['productId']] = ['id' => (string) $order['productId'], 'name' => $order['name']];
                $options['accounts'][(string) $order['customerId']] = ['id' => (string) $order['customerId'], 'name' => $order['customer']];
                $offer = $execution->encrypted_input ? json_decode(Crypt::decryptString($execution->encrypted_input), true)['offer'] ?? [] : [];
                if (! empty($input['group_id']) && ! in_array($input['group_id'], array_map(fn ($group) => (string) $group['remote_id'], $offer['groupPath'] ?? []), true)) {
                    continue;
                }
                if (! empty($input['account_id']) && (string) $order['customerId'] !== $input['account_id'] || ! empty($input['type']) && ($offer['type'] ?? '') !== $input['type']) {
                    continue;
                }
                $hasTime = is_string($order['createdAt'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})$/iD', $order['createdAt']) && strtotime($order['createdAt']) !== false;
                if (! $hasTime && (str_contains($input['from'] ?? '', 'T') || str_contains($input['to'] ?? '', 'T'))) {
                    continue;
                }
                $created = CarbonImmutable::parse($hasTime ? $order['createdAt'] : $order['date'], 'Asia/Baghdad');
                $name = $order['saleSnapshot']['name'] ?? $order['name'];
                $payment = $payments->get($execution->order_id);
                $currentStatus = self::status($execution->state);
                if ($status !== '' && $status !== $currentStatus || $profit && ! in_array($currentStatus, ['succeeded', 'refunded'], true) || $from && $created->timestamp < $from || $to && $created->timestamp >= $to || ! empty($input['product_id']) && (string) $order['productId'] !== (string) $input['product_id']) {
                    continue;
                }
                $retail = StockOrders::money((string) ($payment?->amount ?? $order['saleSnapshot']['total'] ?? $order['price']));
                $cost = $execution->actual_cost !== null ? StockOrders::money((string) $execution->actual_cost) : null;
                if ($execution->provider === 'rabiaa' && $cost !== null && BigDecimal::of($cost)->isZero()) {
                    $cost = null;
                }
                $refund = $refundTotals[$execution->order_id]['amount'] ?? '0.000';
                $recognized = $payment?->delivered_at !== null || $execution->state === 'delivered' || ($refundTotals[$execution->order_id]['revenueRecognized'] ?? false);
                $netRevenue = $recognized ? (string) BigDecimal::of($retail)->minus($refund)->toScale(3) : '0.000';
                $margin = $recognized && $cost !== null ? (string) BigDecimal::of($netRevenue)->minus($cost)->toScale(3) : null;
                $row = ['id' => $execution->order_id, 'request_id' => $order['reference'] ?? $execution->order_id, 'status' => $currentStatus, 'provider' => $execution->provider, 'created_at' => $hasTime ? $created->toIso8601String() : $order['date'], 'time_precision' => $hasTime ? 'second' : 'day', 'product_id' => $order['productId'], 'product_name' => $name, 'account_id' => $order['customerId'], 'account_name' => $order['customer'], 'creator_name' => $order['customer'], 'mobile' => $order['recipient'] ?? '', 'retail' => $retail, 'refund_amount' => $refund, 'net_revenue' => $netRevenue, 'revenue_recognized' => (bool) $recognized, 'cost' => $cost, 'profit' => $margin, 'company_transaction_id' => $execution->provider_reference, 'message' => $execution->reason, 'version' => $execution->updated];
                $row += ['type' => $offer['type'] ?? '', 'connection_id' => $execution->provider, 'offer_id' => $execution->offer_id, 'currency' => 'IQD', 'quoted_retail' => $retail, 'quoted_cost' => StockOrders::money((string) $execution->quoted_cost), 'cost_basis' => $cost !== null ? 'provider_response' : 'unknown', 'quoted_cost_basis' => 'catalog_snapshot', 'dispatch_state' => DigitalDispatches::state($execution, $dispatches[$execution->order_id] ?? []), 'dispatches' => $dispatches[$execution->order_id] ?? [], 'execution' => ['dispatch_started' => $execution->tracking_version ? (bool) $execution->dispatch_started : null, 'dispatch_started_at' => $execution->dispatch_started ? date(DATE_ATOM, $execution->dispatch_started) : null, 'response_received' => $execution->tracking_version ? (bool) $execution->response_received : null, 'waiting_since' => $hasTime ? $created->toIso8601String() : null]];
                $evidence = $execution->encrypted_evidence ? json_decode(Crypt::decryptString($execution->encrypted_evidence), true) : [];
                $row += ['receipt_available' => $execution->provider === 'rabiaa' && in_array($execution->state, ['delivered', 'review'], true) && ! empty($evidence['delivery']['codes']) && ! ($evidence['companyRefund']['confirmed'] ?? false), 'company_purchase_confirmed' => isset($evidence['delivery']) || isset($evidence['companyPurchase']), 'company_refund_found' => ($evidence['companyRefund']['confirmed'] ?? false) === true, 'refund_allowed' => DigitalRefunds::basis($execution, $evidence) !== null, 'refunded_at' => $order['refundedAt'] ?? null];
                if ($query !== '' && ! str_contains(mb_strtolower(implode(' ', array_filter([$row['id'], $row['request_id'], $row['product_name'], $row['account_name'], $row['mobile'], $row['company_transaction_id']]))), mb_strtolower($query))) {
                    continue;
                }
                $rows[] = $row;
            }
            $sort = $input['sort'] ?? 'created_at';
            $direction = $input['direction'] ?? 'desc';
            if ($sort === 'cost' && request()->attributes->has('adminAccess') && ! app(AdminAccess::class)->can('finance.costs')) {
                throw new ApiError('لا توجد صلاحية لفرز الكلفة', 403);
            }
            if (! in_array($sort, ['created_at', 'id', 'status', 'product_name', 'account_name', 'retail', 'request_id', 'provider', 'mobile', 'company_transaction_id', 'cost'], true) || ! in_array($direction, ['asc', 'desc'], true)) {
                throw new ApiError('ترتيب السجل غير صحيح', 422);
            }
            usort($rows, fn ($a, $b) => ($direction === 'asc' ? 1 : -1) * (in_array($sort, ['retail', 'cost'], true) ? BigDecimal::of($a[$sort] ?? 0)->compareTo($b[$sort] ?? 0) : strcmp((string) $a[$sort], (string) $b[$sort])));
            $known = BigDecimal::of(0);
            $unknown = 0;
            $sales = BigDecimal::of(0);
            $refunds = BigDecimal::of(0);
            $refundCount = 0;
            $netSales = BigDecimal::of(0);
            foreach ($rows as $row) {
                if (BigDecimal::of($row['refund_amount'])->isGreaterThan(0)) {
                    $refunds = $refunds->plus($row['refund_amount']);
                    $refundCount++;
                }
                if (! $row['revenue_recognized']) {
                    continue;
                }
                $sales = $sales->plus($row['retail']);
                $netSales = $netSales->plus($row['net_revenue']);
                if ($row['profit'] === null) {
                    $unknown++;
                } else {
                    $known = $known->plus($row['profit']);
                }
            }
            $all = ($input['all'] ?? false) === true;
            if ($all && count($rows) > 10000) {
                throw new ApiError('قلّل نطاق التصدير؛ الحد الأقصى عشرة آلاف عملية', 422);
            }
            $summary = ['known_amount' => (string) $known, 'unknown_count' => $unknown, 'value' => $unknown ? null : (string) $known, 'refunded_amount' => (string) $refunds, 'refund_count' => $refundCount, 'sales' => (string) $sales, 'net_sales' => (string) $netSales, 'count' => count($rows)];
            if (request()->attributes->has('adminAccess') && ! app(AdminAccess::class)->can('finance.profits')) {
                $summary = ['refunded_amount' => (string) $refunds, 'refund_count' => $refundCount, 'sales' => (string) $sales, 'net_sales' => (string) $netSales, 'count' => count($rows)];
            }

            return ['options' => ['products' => array_values($options['products']), 'accounts' => array_values($options['accounts'])], 'data' => $all ? $rows : array_slice($rows, ($page - 1) * $size, $size), 'meta' => ['current_page' => $page, 'per_page' => $size, 'last_page' => max(1, (int) ceil(count($rows) / $size)), 'total' => count($rows)], 'summary' => $summary, 'filters' => $input, 'timezone' => 'Asia/Baghdad', 'generatedAt' => now()->utc()->toIso8601String()];
        });
    }
}
