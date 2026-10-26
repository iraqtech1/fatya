<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderFulfillment
{
    public function __construct(private Store $store, private CustomerNotifications $notifications) {}

    private function lockedState(): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('A transaction is required.');
        }

        return $this->store->read(true);
    }

    public static function amount(string $amount): string
    {
        if (! preg_match('/^\d{1,16}(\.\d{1,3})?$/D', $amount)) {
            throw new ApiError('مبلغ الدفع غير صحيح', 422);
        }

        return (string) BigDecimal::of($amount)->toScale(3, RoundingMode::Unnecessary);
    }

    private function orderIndex(array $s, string $id): int
    {
        $index = array_search($id, array_column($s['orders'], 'id'), true);
        if ($index === false) {
            throw new ApiError('الطلب غير موجود', 404);
        }

        return $index;
    }

    private function updateSummary(array &$s, string $id): void
    {
        $f = DB::table('order_fulfillments')->where('order_id', $id)->first();
        $i = $this->orderIndex($s, $id);
        $s['orders'][$i]['paymentStatus'] = $f->payment_status;
        $s['orders'][$i]['fulfillmentStatus'] = $f->state;
        $s['orders'][$i]['reservationExpiresAt'] = $f->expires * 1000;
        $s['orders'][$i]['codesAvailable'] = $f->state === 'delivered' && $f->payment_status === 'confirmed';
        $s['orders'][$i]['paymentReviewReason'] = $f->review_reason;
        $s['orders'][$i]['status'] = match ($f->state) {
            'delivered' => 'مكتمل', 'expired', 'cancelled' => 'ملغي', default => 'قيد المراجعة',
        };
    }

    public function receipts(): array
    {
        return DB::table('payment_receipts')->orderByDesc('received_at')->orderBy('id')->get()->map(fn ($r) => ['id' => $r->id, 'orderId' => $r->order_id, 'provider' => $r->provider, 'transactionId' => $r->transaction_id, 'eventId' => $r->event_id, 'amount' => $r->amount, 'currency' => $r->currency, 'outcome' => $r->outcome, 'reason' => $r->reason, 'receivedAt' => $r->received_at * 1000])->all();
    }

    public function cancel(string $id, array $customer): array
    {
        $this->expire();

        return DB::transaction(function () use ($id, $customer) {
            $s = $this->lockedState();
            $i = $this->orderIndex($s, $id);
            if ($s['orders'][$i]['customerId'] !== $customer['id']) {
                throw new ApiError('الطلب غير موجود', 404);
            }
            $f = DB::table('order_fulfillments')->where('order_id', $id)->lockForUpdate()->first();
            if (! $f) {
                throw new ApiError('الطلب يحتاج مراجعة الإدارة', 409);
            }
            if (in_array($f->state, ['cancelled', 'expired'], true)) {
                return $s['orders'][$i];
            }
            if ($f->state !== 'reserved' || $f->payment_status !== 'pending') {
                throw new ApiError('لا يمكن إلغاء طلب مرتبط بتأكيد دفع', 409);
            }
            $updated = $this->adminStatus($id, 'ملغي');

            return $updated['orders'][$this->orderIndex($updated, $id)];
        }, 3);
    }

    private function release(string $id): void
    {
        DB::table('inventory_cards')->where('reserved_order_id', $id)->whereNull('sold_order_id')->update(['reserved_order_id' => null]);
    }

    private function releaseExpired(array &$s): int
    {
        $expired = DB::table('order_fulfillments')->where('state', 'reserved')->where('expires', '<=', now()->timestamp)->lockForUpdate()->get();
        foreach ($expired as $f) {
            $this->release($f->order_id);
            DB::table('order_fulfillments')->where('order_id', $f->order_id)->update(['state' => 'expired']);
            $this->updateSummary($s, $f->order_id);
        }
        if ($expired->count()) {
            app(Inventory::class)->refreshStock($s);
        }

        return $expired->count();
    }

    public function expire(): int
    {
        return DB::transaction(function () {
            $s = $this->lockedState();
            $count = $this->releaseExpired($s);
            if ($count) {
                $this->store->event($s, 'انتهاء حجز '.$count.' طلب');
                $this->store->save($s);
            }

            return $count;
        }, 3);
    }

    public function reserve(array $customer, array $b): array
    {
        $this->expire();

        // A nested transaction rolls back ALL financial writes on validation failure,
        // even though the outer HTTP controller intentionally retains failed OTP attempts.
        return DB::transaction(function () use ($customer, $b) {
            $s = $this->lockedState();
            $reference = CatalogEditor::text($b['reference'] ?? null, 100);
            if (! $reference) {
                throw new ApiError('مرجع الطلب مطلوب');
            }
            $old = collect($s['orders'])->first(fn ($o) => $o['customerId'] === $customer['id'] && $o['reference'] === $reference);
            if ($old) {
                if ($old['productId'] !== ($b['productId'] ?? null) || $old['quantity'] !== ($b['quantity'] ?? null) || $old['payment'] !== ($b['payment'] ?? null) || (isset($old['denomIndex']) && $old['denomIndex'] !== ($b['denom'] ?? null))) {
                    throw new ApiError('مرجع الطلب مستخدم بتفاصيل مختلفة', 409);
                }

                return ['order' => $old, 'created' => false];
            }
            $p = collect($s['products'])->first(fn ($p) => $p['id'] === ($b['productId'] ?? null) && $p['active'] && collect($s['categories'])->contains(fn ($c) => $c['active'] && $c['name'] === $p['category']));
            $denom = $b['denom'] ?? null;
            $quantity = $b['quantity'] ?? null;
            if (! $p || ! is_int($denom) || $denom < 0 || $denom >= count($p['values']) || ! is_int($quantity) || $quantity < 1 || $quantity > 20) {
                throw new ApiError('تفاصيل الطلب غير صحيحة');
            }
            if (! in_array($b['payment'] ?? null, ['Qi', 'ZainCash'], true)) {
                throw new ApiError('وسيلة الدفع غير صحيحة');
            }
            if (DB::table('order_fulfillments')->join('orders', 'orders.id', '=', 'order_fulfillments.order_id')->where('orders.customer_id', $customer['id'])->where('order_fulfillments.state', 'reserved')->count() >= 5) {
                throw new ApiError('أكمل أو ألغِ طلباتك المحجوزة أولاً', 429);
            }
            $cards = DB::table('inventory_cards')->where('product_id', (string) $p['id'])->where('denomination', $p['values'][$denom])->whereNull('reserved_order_id')->whereNull('sold_order_id')->orderBy('id')->limit($quantity)->lockForUpdate()->get();
            if ($cards->count() !== $quantity) {
                throw new ApiError('المخزون غير كافٍ لهذه الفئة', 409);
            }
            $amount = self::amount((string) BigDecimal::of(self::amount((string) $p['prices'][$denom]))->multipliedBy($quantity)->toScale(3));
            $id = 'ORDER-'.Str::uuid();
            $minutes = max(1, min(60, (int) config('fatya.reservation_minutes', 15)));
            $o = ['id' => $id, 'reference' => $reference, 'customerId' => $customer['id'], 'customer' => $customer['name'], 'phone' => $customer['phone'], 'email' => $customer['email'], ...isset($customer['previewId']) ? ['previewId' => $customer['previewId']] : [], 'productId' => $p['id'], 'name' => $p['name'], 'unit' => $p['unit'], 'value' => $p['values'][$denom], 'denomIndex' => $denom, 'quantity' => $quantity, 'price' => (float) $amount, 'date' => now('Asia/Baghdad')->toDateString(), 'kind' => $customer['kind'], 'status' => 'قيد المراجعة', 'payment' => $b['payment']];
            array_unshift($s['orders'], $o);
            // Insert the order before FK-constrained reservations; save again after allocation.
            $this->store->save($s);
            DB::table('order_fulfillments')->insert(['order_id' => $id, 'state' => 'reserved', 'provider' => $b['payment'], 'amount' => $amount, 'currency' => 'IQD', 'quantity' => $quantity, 'expires' => now()->addMinutes($minutes)->timestamp]);
            foreach ($cards as $card) {
                DB::table('reservation_items')->insert(['order_id' => $id, 'card_id' => $card->id]);
            }
            DB::table('inventory_cards')->whereIn('id', $cards->pluck('id'))->update(['reserved_order_id' => $id]);
            $this->updateSummary($s, $id);
            app(Inventory::class)->refreshStock($s);
            $this->store->event($s, 'حجز مخزون لطلب جديد: '.$p['name']);
            $this->store->save($s);
            $this->notifications->send($customer['id'], 'تم إضافة طلبك', 'تم حجز البطاقة بانتظار تأكيد الدفع', 'order-'.$id);

            return ['order' => $s['orders'][$this->orderIndex($s, $id)], 'created' => true];
        }, 3);
    }

    public function adminStatus(string $id, mixed $status): array
    {
        return DB::transaction(function () use ($id, $status) {
            $s = $this->lockedState();
            $i = $this->orderIndex($s, $id);
            if (! in_array($status, ['قيد المراجعة', 'مكتمل', 'ملغي'], true)) {
                throw new ApiError('طلب غير صحيح');
            }
            $f = DB::table('order_fulfillments')->where('order_id', $id)->lockForUpdate()->first();
            if ($status === 'مكتمل') {
                if (! $f || $f->state !== 'delivered' || $f->payment_status !== 'confirmed') {
                    throw new ApiError('إكمال الطلب يتطلب تأكيد دفع موثوق وتسليم الكارت', 409);
                }

                return $s;
            }
            if ($f && ($f->state === 'delivered' || $f->payment_status !== 'pending')) {
                throw new ApiError('الطلب مرتبط بدفع ويحتاج مراجعة مستقلة', 409);
            }
            if ($f && $status === 'قيد المراجعة' && $f->state !== 'reserved') {
                throw new ApiError('الحجز منتهي أو ملغي؛ أنشئ طلباً جديداً', 409);
            }
            if ($s['orders'][$i]['status'] === $status) {
                return $s;
            }
            if ($f && $status === 'ملغي') {
                $this->release($id);
                DB::table('order_fulfillments')->where('order_id', $id)->update(['state' => 'cancelled']);
                $this->updateSummary($s, $id);
                app(Inventory::class)->refreshStock($s);
            } else {
                $s['orders'][$i]['status'] = $status;
            }
            $this->store->event($s, 'تحديث حالة الطلب: '.$id);
            $this->store->save($s);
            $this->notifications->send($s['orders'][$i]['customerId'], 'تحديث حالة الطلب', $s['orders'][$i]['name'].' · '.$status);

            return $s;
        }, 3);
    }

    public function confirm(VerifiedPayment $payment): array
    {
        return DB::transaction(function () use ($payment) {
            $s = $this->lockedState();
            $i = $this->orderIndex($s, $payment->orderId);
            $amount = self::amount($payment->amount);
            foreach ([$payment->transactionId, $payment->eventId] as $key) {
                if (! $key || strlen($key) > 150) {
                    throw new ApiError('مرجع الدفع غير صحيح', 422);
                }
            }
            if (! in_array($payment->provider, ['Qi', 'ZainCash'], true) || ! preg_match('/^[A-Z]{3}$/D', $payment->currency)) {
                throw new ApiError('تأكيد دفع غير صحيح', 422);
            }
            $existing = DB::table('payment_receipts')->where('provider', $payment->provider)->where(function ($q) use ($payment) {
                $q->where('transaction_id', $payment->transactionId)->orWhere('event_id', $payment->eventId);
            })->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                foreach ($existing as $receipt) {
                    if ($receipt->order_id !== $payment->orderId || $receipt->transaction_id !== $payment->transactionId || $receipt->amount !== $amount || $receipt->currency !== $payment->currency) {
                        throw new ApiError('مرجع الدفع مستخدم بتفاصيل مختلفة', 409);
                    }
                }
                $receipt = $existing->first();

                return ['orderId' => $payment->orderId, 'outcome' => $receipt->outcome, 'reason' => $receipt->reason, 'duplicate' => true];
            }
            $this->releaseExpired($s);
            $f = DB::table('order_fulfillments')->where('order_id', $payment->orderId)->lockForUpdate()->first();
            $customer = collect($s['customers'])->firstWhere('id', $s['orders'][$i]['customerId']);
            $reason = match (true) {
                ! $f => 'legacy_order_without_reservation',
                $f->provider !== $payment->provider => 'provider_mismatch',
                $f->amount !== $amount => 'amount_mismatch',
                $f->currency !== $payment->currency => 'currency_mismatch',
                $f->state === 'delivered' => 'additional_payment',
                $f->state !== 'reserved' => 'reservation_'.$f->state,
                ! ($customer['active'] ?? false) => 'customer_inactive',
                default => null,
            };
            $cards = collect();
            if (! $reason) {
                $cards = DB::table('inventory_cards')->where('reserved_order_id', $payment->orderId)->whereNull('sold_order_id')->orderBy('id')->lockForUpdate()->get();
                if ($cards->count() !== $f->quantity) {
                    $reason = 'allocation_incomplete';
                }
                $expected = DB::table('reservation_items')->where('order_id', $payment->orderId)->orderBy('card_id')->pluck('card_id')->all();
                if ($cards->pluck('id')->all() !== $expected || $cards->contains(fn ($card) => $card->product_id !== (string) $s['orders'][$i]['productId'] || (float) $card->denomination !== (float) $s['orders'][$i]['value'])) {
                    $reason = 'allocation_incomplete';
                }
                // Check the encryption key before recording acceptance or delivering any item.
                if (! $reason) {
                    foreach ($cards as $card) {
                        Crypt::decryptString($card->encrypted_code);
                    }
                }
            }
            $outcome = $reason ? 'review' : 'delivered';
            DB::table('payment_receipts')->insert(['id' => (string) Str::uuid(), 'order_id' => $payment->orderId, 'provider' => $payment->provider, 'transaction_id' => $payment->transactionId, 'event_id' => $payment->eventId, 'amount' => $amount, 'currency' => $payment->currency, 'outcome' => $outcome, 'reason' => $reason, 'received_at' => now()->timestamp]);
            if (! $reason) {
                foreach ($cards as $card) {
                    DB::table('delivered_cards')->insert(['card_id' => $card->id, 'order_id' => $payment->orderId, 'delivered_at' => now()->timestamp]);
                }
                DB::table('inventory_cards')->where('reserved_order_id', $payment->orderId)->whereNull('sold_order_id')->update(['reserved_order_id' => null, 'sold_order_id' => $payment->orderId]);
                DB::table('order_fulfillments')->where('order_id', $payment->orderId)->update(['state' => 'delivered', 'payment_status' => 'confirmed', 'delivered_at' => now()->timestamp, 'review_reason' => null]);
            } elseif ($f) {
                $changes = ['review_reason' => $reason];
                if ($f->state !== 'delivered') {
                    $changes['payment_status'] = 'review';
                }
                if (in_array($reason, ['customer_inactive', 'allocation_incomplete'])) {
                    $this->release($payment->orderId);
                    $changes['state'] = 'review';
                }
                DB::table('order_fulfillments')->where('order_id', $payment->orderId)->update($changes);
            }
            if ($f) {
                $this->updateSummary($s, $payment->orderId);
            }
            app(Inventory::class)->refreshStock($s);
            $this->store->event($s, $reason ? 'دفع يحتاج مراجعة: '.$payment->orderId : 'تأكيد الدفع وتسليم الطلب: '.$payment->orderId);
            $this->store->save($s);
            $this->notifications->send($customer['id'], 'تحديث حالة الطلب', $reason ? 'وصل تأكيد دفع يحتاج مراجعة الإدارة' : 'تم تأكيد الدفع وأصبحت أكواد طلبك متاحة');

            return ['orderId' => $payment->orderId, 'outcome' => $outcome, 'reason' => $reason, 'duplicate' => false];
        }, 3);
    }

    public function codes(string $id, array $customer): array
    {
        $row = DB::table('orders')->where('id', $id)->where('customer_id', $customer['id'])->first();
        if (! $row) {
            throw new ApiError('الطلب غير موجود', 404);
        }
        $f = DB::table('order_fulfillments')->where('order_id', $id)->first();
        if (! $f || $f->state !== 'delivered' || $f->payment_status !== 'confirmed') {
            throw new ApiError('أكواد الطلب تتاح بعد تأكيد الدفع والتسليم', 409);
        }
        $cards = DB::table('delivered_cards')->join('inventory_cards', 'inventory_cards.id', '=', 'delivered_cards.card_id')->where('delivered_cards.order_id', $id)->where('inventory_cards.sold_order_id', $id)->orderBy('inventory_cards.id')->get();
        if ($cards->count() !== $f->quantity) {
            throw new ApiError('بيانات التسليم تحتاج مراجعة', 409);
        }

        return ['orderId' => $id, 'codes' => $cards->map(fn ($card) => ['serial' => $card->serial, 'value' => (float) $card->denomination, 'code' => Crypt::decryptString($card->encrypted_code)])->all()];
    }
}
