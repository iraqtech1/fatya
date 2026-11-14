<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\DB;

/** Read only recorded inventory facts. Missing legacy events are never reconstructed. */
class CardLifecycle
{
    public function read(array $body): array
    {
        $kind = $body['kind'] ?? 'serial';
        $key = CatalogEditor::text($body['query'] ?? '', 256);
        $page = $body['page'] ?? 1;
        if (! $key || ! in_array($kind, ['serial', 'card', 'order', 'batch', 'importCode', 'pin'], true) || ! is_int($page) || $page < 1 || $page > 10000) {
            throw new ApiError('اختر نوع الاستعلام واكتب معرّفاً صحيحاً', 422);
        }
        if ($kind === 'card' && ! preg_match('/^[1-9][0-9]{0,19}$/D', $key)) {
            throw new ApiError('رقم الكارت يجب أن يكون رقماً صحيحاً', 422);
        }
        $query = DB::table('inventory_cards');
        match ($kind) {
            'serial' => $query->where('serial', $key),
            'card' => $query->where('id', $key),
            'order' => $query->where(fn ($query) => $query->where('sold_order_id', $key)->orWhere('reserved_order_id', $key)),
            'batch' => $query->where('batch_id', $key),
            'pin' => $query->where('code_hash', hash('sha256', $key)),
            'importCode' => $query->where(function ($query) use ($key) {
                $query->whereRaw('1 = 0');
                $code = ImportCategories::code($key);
                foreach (DB::table('stock_batches')->get() as $batch) {
                    foreach (json_decode($batch->summary, true)['lines'] ?? [] as $line) {
                        if (($line['categoryCode'] ?? '') === $code) {
                            $query->orWhere(fn ($query) => $query->where('batch_id', $batch->id)->where('source_line', $line['line']));
                        }
                    }
                }
            }),
        };
        $count = (clone $query)->count();
        $pages = max(1, (int) ceil($count / 50));
        $page = min($page, $pages);
        $state = app(Store::class)->read();
        if ($kind === 'order' && $count === 0 && app(AdminAccess::class)->can('digital.executions')) {
            $execution = DB::table('digital_executions')->where('order_id', $key)->first();
            $order = collect($state['orders'])->firstWhere('id', $key);
            if ($execution && $order) {
                $fulfillment = DB::table('order_fulfillments')->where('order_id', $key)->first();
                $timeline = [];
                if (! empty($order['createdAt'])) {
                    $timeline[] = ['event' => 'إنشاء طلب خدمة رقمية', 'at' => $order['createdAt'], 'reference' => $key];
                }
                foreach (DigitalDispatches::forOrders([$key])[$key] ?? [] as $dispatch) {
                    $timeline[] = ['event' => $dispatch['endpoint'] === '/vendor/hold' ? 'تسجيل إرسال الحجز إلى المزوّد' : 'تسجيل إرسال الشراء إلى المزوّد', 'at' => $dispatch['startedAt'], 'reference' => $dispatch['id']];
                }
                if ($fulfillment?->delivered_at) {
                    $timeline[] = ['event' => 'تسجيل نجاح تنفيذ الخدمة', 'at' => gmdate(DATE_ATOM, $fulfillment->delivered_at), 'reference' => $execution->provider_reference];
                }
                foreach (DB::table('digital_refund_entries')->where('order_id', $key)->orderBy('created')->get() as $refund) {
                    $timeline[] = ['event' => 'توثيق مبلغ أُعيد للزبون', 'at' => gmdate(DATE_ATOM, $refund->created), 'reference' => $refund->refund_reference];
                }
                $row = ['id' => $key, 'name' => $order['saleSnapshot']['serviceTitle'] ?? $order['name'], 'productId' => (string) $order['productId'], 'value' => $order['saleSnapshot']['denominationName'] ?? '', 'serial' => null, 'status' => $execution->state, 'expiry' => null, 'batchId' => null, 'sourceLine' => null, 'sourceRow' => null, 'orderId' => $key, 'source' => $execution->provider, 'company' => $order['saleSnapshot']['companyName'] ?? null, 'paymentStatus' => $fulfillment?->payment_status, 'deliveryStatus' => $fulfillment?->state, 'timeline' => $timeline, 'historyNote' => 'سجل خدمة API من الطلب الأصلي؛ لا تمثل بطاقة مضافة إلى المخزون ولا تعرض PIN. السجل القديم لا يكتسب أدلة إرسال مفترضة'];
                ProcurementAudit::record('card_lookup', null, ['kind' => $kind, 'results' => 1, 'page' => 1]);

                return ['rows' => [$row], 'count' => 1, 'page' => 1, 'pages' => 1];
            }
        }
        $rows = $query->orderBy('id')->offset(($page - 1) * 50)->limit(50)->get()->map(function ($card) use ($state) {
            $product = collect($state['products'])->first(fn ($product) => (string) $product['id'] === $card->product_id);
            $timeline = [];
            if ($card->imported_at) {
                $timeline[] = ['event' => 'استيراد إلى المخزن', 'at' => gmdate(DATE_ATOM, $card->imported_at), 'reference' => $card->batch_id];
            }
            $orderId = $card->sold_order_id ?? $card->reserved_order_id;
            $order = $orderId ? collect($state['orders'])->firstWhere('id', $orderId) : null;
            $fulfillment = $orderId ? DB::table('order_fulfillments')->where('order_id', $orderId)->first() : null;
            if ($order && ! empty($order['createdAt'])) {
                $timeline[] = ['event' => 'إنشاء طلب مرتبط', 'at' => $order['createdAt'], 'reference' => $orderId];
            }
            if ($fulfillment?->delivered_at) {
                $timeline[] = ['event' => 'تسليم بعد تأكيد الدفع', 'at' => gmdate(DATE_ATOM, $fulfillment->delivered_at), 'reference' => $orderId];
            }
            if ($orderId) {
                foreach (DB::table('digital_refund_entries')->where('order_id', $orderId)->orderBy('created')->get() as $refund) {
                    $timeline[] = ['event' => 'توثيق مبلغ أُعيد للزبون', 'at' => gmdate(DATE_ATOM, $refund->created), 'reference' => $refund->refund_reference];
                }
            }
            if ($card->withdrawal_id) {
                $withdrawal = DB::table('inventory_exports')->where('id', $card->withdrawal_id)->first();
                if ($withdrawal?->approved_at) {
                    $timeline[] = ['event' => 'سحب مخزون معتمد', 'at' => gmdate(DATE_ATOM, $withdrawal->approved_at), 'reference' => $card->withdrawal_id];
                }
            }
            usort($timeline, fn ($a, $b) => strtotime($a['at']) <=> strtotime($b['at']));

            $company = collect($state['companies'])->firstWhere('id', $product['manufacturerId'] ?? '');
            $batch = $card->batch_id ? json_decode(DB::table('stock_batches')->where('id', $card->batch_id)->value('summary') ?? '{}', true) : [];

            return ['company' => $company['name'] ?? null, 'source' => $batch['supplier'] ?? null, 'importActor' => $batch['actor'] ?? null, 'id' => (string) $card->id, 'name' => $product['name'] ?? 'بطاقة سابقة', 'productId' => $card->product_id, 'value' => (string) $card->denomination, 'serial' => $card->serial, 'status' => InventoryReports::state($card), 'expiry' => $card->expires_on, 'batchId' => $card->batch_id, 'sourceLine' => $card->source_line, 'sourceRow' => $card->source_row, 'orderId' => $orderId, 'paymentStatus' => $fulfillment?->payment_status, 'deliveryStatus' => $fulfillment?->state, 'timeline' => $timeline, 'historyNote' => 'يعرض الأحداث المحفوظة فقط؛ أي حركة غير مسجلة سابقاً لا يمكن استنتاجها'];
        })->all();
        ProcurementAudit::record('card_lookup', null, ['kind' => $kind, 'results' => count($rows), 'page' => $page]);

        return ['rows' => $rows, 'count' => $count, 'page' => $page, 'pages' => $pages];
    }
}
