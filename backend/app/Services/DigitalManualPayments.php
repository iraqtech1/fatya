<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DigitalManualPayments
{
    public static function enabled(string $provider): bool
    {
        return in_array($provider, ['topup', 'rabiaa'], true) && (bool) config('digital.manual_'.$provider.'_enabled');
    }

    public static function approved(?string $id, string $provider = 'topup'): bool
    {
        if (! $id || ! self::enabled($provider)) {
            return false;
        }

        return DB::table('digital_executions')->where('order_id', $id)->where('provider', $provider)->exists()
            && DB::table('digital_manual_payments')->where('order_id', $id)->exists()
            && DB::table('order_fulfillments')->where('order_id', $id)->where('provider', 'Manual')->where('payment_status', 'confirmed')->where('state', 'processing')->exists()
            && DB::table('payment_receipts')->where('order_id', $id)->where('provider', 'Manual')->where('outcome', 'processing')->exists();
    }

    public function confirm(array $b): array
    {
        if (! app(AdminAccess::class)->confirmPassword($b['password'] ?? null)) {
            throw new ApiError('تأكيد الاستلام يحتاج كلمة مرور الإدارة', 403);
        }
        $id = CatalogEditor::text($b['orderId'] ?? '', 100);
        $key = $b['requestId'] ?? null;
        $reference = CatalogEditor::text($b['receiptReference'] ?? '', 100);
        $note = CatalogEditor::text($b['note'] ?? '', 1000);
        if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key) || ! $reference || ! $note || ($b['received'] ?? null) !== true) {
            throw new ApiError('أكد استلام المبلغ وأدخل مرجع الاستلام والملاحظة', 422);
        }
        if (! is_string($b['amount'] ?? null) && ! is_int($b['amount'] ?? null)) {
            throw new ApiError('المبلغ غير صحيح', 422);
        }
        $amount = OrderFulfillment::amount((string) $b['amount']);
        $execution = DB::table('digital_executions')->where('order_id', $id)->first();
        if (! $execution || ! self::enabled($execution->provider)) {
            throw new ApiError('الدفع اليدوي غير متاح لهذه الخدمة', 409);
        }
        $provider = $execution->provider;
        $input = json_decode(Crypt::decryptString($execution->encrypted_input), true);
        // Only a read happens before the lock. Financial POSTs remain exclusively in the worker.
        if (! DB::table('digital_manual_payments')->where('order_id', $id)->exists()) {
            app(DigitalProviders::class)->catalog($provider, $input['offer']['provinceId'] ?? null);
        }
        $result = DB::transaction(function () use ($id, $key, $reference, $note, $amount, $provider) {
            $store = app(Store::class);
            $s = $store->read(true);
            $old = DB::table('digital_manual_payments')->where('order_id', $id)->first();
            if ($old) {
                if ($old->request_id !== $key || $old->receipt_reference !== $reference || OrderFulfillment::amount((string) $old->amount) !== $amount || $old->note !== $note) {
                    throw new ApiError('تم تأكيد هذا الطلب؛ تفاصيل المحاولة مختلفة', 409);
                }

                return ['orderId' => $id, 'duplicate' => true];
            }
            $order = collect($s['orders'])->firstWhere('id', $id);
            $f = DB::table('order_fulfillments')->where('order_id', $id)->lockForUpdate()->first();
            $execution = DB::table('digital_executions')->where('order_id', $id)->first();
            $customer = $order ? collect($s['customers'])->firstWhere('id', $order['customerId']) : null;
            if (! $f || ! $order || ! $customer || ! $customer['active'] || $f->provider !== 'Manual' || $f->state !== 'reserved' || $f->payment_status !== 'pending' || $f->expires <= time() || $execution?->provider !== $provider || $execution->state !== 'awaiting_payment') {
                throw new ApiError('الطلب غير قابل لتأكيد الاستلام؛ حدّث الحالة', 409);
            }
            if (OrderFulfillment::amount((string) $f->amount) !== $amount) {
                throw new ApiError('المبلغ المستلم لا يطابق إجمالي الطلب', 422);
            }
            $publication = DB::table('digital_publications')->where('id', $execution->offer_id)->where('active', true)->first();
            $publication ??= DB::table('digital_offers')->where('id', $execution->offer_id)->where('connection', $provider)->where('active', true)->first();
            if (! $publication || OrderFulfillment::amount((string) $publication->retail) !== $amount) {
                throw new ApiError('النشر أو سعر البيع تغير؛ لا يمكن تنفيذ الطلب القديم', 409);
            }
            $saved = json_decode(Crypt::decryptString($execution->encrypted_input), true);
            $settings = app(DigitalProviders::class)->settings($provider);
            $catalog = app(DigitalProviders::class)->catalogRecord($provider, $saved['offer']['provinceId'] ?? null);
            $current = collect((json_decode($catalog?->snapshot ?? '{}', true)['offers'] ?? []))->first(fn ($o) => $o['providerId'] === $saved['offer']['providerId'] && $o['type'] === $saved['offer']['type']);
            if ($settings['version'] !== (int) $execution->connection_version || ! $current || ! $current['available'] || $current['cost'] !== $saved['offer']['cost']) {
                throw new ApiError('بيانات الشركة أو التكلفة تغيرت؛ راجع الطلب قبل استلام المبلغ', 409);
            }
            if (DB::table('digital_manual_payments')->where('request_id', $key)->orWhere('receipt_reference', $reference)->exists()) {
                throw new ApiError('مرجع الاستلام مستخدم لطلب آخر', 409);
            }
            DB::table('digital_manual_payments')->insert(['order_id' => $id, 'request_id' => $key, 'receipt_reference' => $reference, 'amount' => $amount, 'actor' => ProcurementAudit::actor(), 'note' => $note, 'created' => time()]);
            $result = app(OrderFulfillment::class)->confirm(new VerifiedPayment($id, 'Manual', $reference, $key, $amount, 'IQD'));
            if ($result['outcome'] !== 'processing') {
                throw new ApiError('الطلب يحتاج مراجعة؛ لم يعتمد الاستلام', 409);
            }
            ProcurementAudit::record('digital.manual_payment', $id, ['amount' => $amount, 'receiptReference' => $reference]);

            return $result;
        }, 3);
        // The state claim makes retries and concurrent cron workers unable to send a second purchase.
        app(DigitalSales::class)->execute($id);

        return $result;
    }
}
