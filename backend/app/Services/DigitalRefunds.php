<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Records an actual customer repayment; never simulates a gateway transfer or wallet credit. */
class DigitalRefunds
{
    public static function totals(): array
    {
        $totals = [];
        foreach (DB::table('digital_refund_entries')->orderBy('created')->orderBy('id')->get() as $entry) {
            $old = $totals[$entry->order_id]['amount'] ?? '0.000';
            $totals[$entry->order_id] = ['amount' => (string) BigDecimal::of($old)->plus((string) $entry->amount)->toScale(3), 'revenueRecognized' => (bool) $entry->revenue_recognized, 'lastAt' => date(DATE_ATOM, $entry->created)];
        }
        foreach (DB::table('digital_refund_settlements')->get() as $entry) {
            if (! isset($totals[$entry->order_id])) {
                $totals[$entry->order_id] = ['amount' => StockOrders::money((string) $entry->amount), 'revenueRecognized' => null, 'lastAt' => date(DATE_ATOM, $entry->created)];
            }
        }

        return $totals;
    }

    public static function basis(object $execution, array $evidence): ?string
    {
        if (($evidence['companyRefund']['confirmed'] ?? false) === true && in_array($execution->state, ['review', 'delivered'], true)) {
            return 'company_refund';
        }
        if ($execution->state === 'failed' && $execution->reason === 'provider_rejected') {
            return 'provider_rejected';
        }
        if ($execution->state === 'review' && $execution->tracking_version && ! $execution->dispatch_started) {
            return 'not_dispatched';
        }

        return null;
    }

    public function settle(array $input, bool $includeStock = false): array
    {
        if (! app(AdminAccess::class)->confirmPassword($input['password'] ?? null)) {
            throw new ApiError('توثيق الاسترجاع يحتاج كلمة مرور الإدارة', 403);
        }
        $id = CatalogEditor::text($input['orderId'] ?? '', 100);
        $reference = CatalogEditor::text($input['refundReference'] ?? '', 100);
        $note = CatalogEditor::text($input['note'] ?? '', 1000);
        $key = $input['requestId'] ?? null;
        if (! $id || ! $reference || ! $note || ($input['returned'] ?? null) !== true || ! is_string($key) || ! preg_match('/^[A-Za-z0-9_-]{8,100}$/D', $key)) {
            throw new ApiError('أكد إعادة المبلغ فعلياً وأدخل وصل الاسترجاع والملاحظة', 422);
        }
        $amount = StockOrders::money($input['amount'] ?? null);
        if (! BigDecimal::of($amount)->isGreaterThan(0)) {
            throw new ApiError('مبلغ الاسترجاع يجب أن يكون موجباً', 422);
        }

        return DB::transaction(function () use ($id, $reference, $note, $key, $amount, $includeStock) {
            $store = app(Store::class);
            $state = $store->read(true);
            $execution = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            $payment = DB::table('order_fulfillments')->where('order_id', $id)->lockForUpdate()->first();
            if ((! $execution && ! $includeStock) || ! $payment || $payment->payment_status !== 'confirmed') {
                throw new ApiError('لم يثبت دفع هذا الطلب', 409);
            }
            $old = DB::table('digital_refund_settlements')->where('order_id', $id)->first();
            if ($old) {
                if ($old->request_id !== $key || $old->refund_reference !== $reference || StockOrders::money((string) $old->amount) !== $amount || $old->note !== $note) {
                    throw new ApiError('الاسترجاع موثق مسبقاً بتفاصيل مختلفة', 409);
                }

                return ['orderId' => $id, 'state' => 'refunded', 'duplicate' => true];
            }
            $duplicate = DB::table('digital_refund_entries')->where('request_id', $key)->first();
            if ($duplicate) {
                if ($duplicate->order_id !== $id || $duplicate->refund_reference !== $reference || StockOrders::money((string) $duplicate->amount) !== $amount || $duplicate->note !== $note) {
                    throw new ApiError('مرجع الاسترجاع مستخدم بتفاصيل مختلفة', 409);
                }

                return ['orderId' => $id, 'state' => $execution?->state ?? $payment->state, 'duplicate' => true];
            }
            $evidence = $execution?->encrypted_evidence ? json_decode(Crypt::decryptString($execution->encrypted_evidence), true) : [];
            $basis = $execution ? self::basis($execution, $evidence) : ($payment->state === 'delivered' ? 'stock_repayment_no_restock' : null);
            if (! $basis) {
                throw new ApiError('نتيجة الشركة غير محسومة؛ لا يمكن تسوية الاسترجاع', 409);
            }
            $previous = self::totals()[$id]['amount'] ?? '0.000';
            $total = BigDecimal::of($previous)->plus($amount);
            if ($total->isGreaterThan((string) $payment->amount)) {
                throw new ApiError('مجموع الاسترجاعات يتجاوز دفعة الطلب المثبتة', 422);
            }
            $full = $total->isEqualTo((string) $payment->amount);
            if (DB::table('digital_refund_settlements')->where('request_id', $key)->orWhere('refund_reference', $reference)->exists() || DB::table('digital_refund_entries')->where('refund_reference', $reference)->exists()) {
                throw new ApiError('وصل الاسترجاع مستخدم لطلب آخر', 409);
            }
            $index = array_search($id, array_column($state['orders'], 'id'), true);
            if ($index === false) {
                throw new ApiError('الطلب غير موجود', 404);
            }
            DB::table('digital_refund_entries')->insert(['id' => (string) Str::uuid(), 'order_id' => $id, 'request_id' => $key, 'refund_reference' => $reference, 'amount' => $amount, 'actor' => ProcurementAudit::actor(), 'note' => $note, 'basis' => $basis, 'revenue_recognized' => $payment->delivered_at !== null || $execution?->state === 'delivered' || $payment->state === 'delivered', 'created' => time()]);
            if ($full) {
                DB::table('digital_executions')->where('order_id', $id)->update(['state' => 'refunded', 'reason' => 'customer_refund_settled', 'updated' => time()]);
                DB::table('order_fulfillments')->where('order_id', $id)->update(['state' => 'refunded', 'review_reason' => null]);
            }
            $state['orders'][$index] = [...$state['orders'][$index], 'refundStatus' => $full ? 'settled' : 'partial', 'refundAmount' => (string) $total->toScale(3), 'lastRefundAt' => now()->toIso8601String(), ...$full ? ['fulfillmentStatus' => 'refunded', 'refundedAt' => now()->toIso8601String(), 'codesAvailable' => false, 'status' => 'مسترجع', 'paymentReviewReason' => null] : []];
            $store->event($state, 'توثيق إعادة مبلغ طلب الخدمة: '.$id);
            $store->save($state);
            ProcurementAudit::record('digital.refund_settled', $id, ['amount' => $amount, 'totalRefunded' => (string) $total, 'reference' => $reference, 'basis' => $basis, 'full' => $full]);
            app(CustomerNotifications::class)->send($state['orders'][$index]['customerId'], 'استرجاع مبلغ الطلب', 'وثقت الإدارة إعادة '.$amount.' IQD من مبلغ طلب الخدمة إليك', 'digital-refund-'.$key);

            return ['orderId' => $id, 'state' => $full ? 'refunded' : ($execution?->state ?? $payment->state), 'refundStatus' => $full ? 'settled' : 'partial', 'refundedAmount' => (string) $total, 'duplicate' => false];
        }, 3);
    }
}
