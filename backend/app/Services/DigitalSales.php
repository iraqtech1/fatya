<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Exceptions\ProviderTransportError;
use App\Services\Captain\TopupResult;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Customer orders use the existing trusted payment boundary. No HTTP endpoint can confirm payment. */
class DigitalSales
{
    public function mapping(string $product, int $denom): ?object
    {
        if (! config('digital.purchases_enabled') && ! DigitalManualPayments::enabled('rabiaa')) {
            return null;
        }

        return DB::table('digital_offers')->where('product_id', $product)->where('denom_index', $denom)->where('active', true)->when(! config('digital.purchases_enabled'), fn ($q) => $q->where('connection', 'rabiaa'))->first();
    }

    private function currentOffer(?object $catalog, array $offer, bool $requireFresh = true): bool
    {
        if (! $catalog || ($requireFresh && $catalog->synced < time() - 900)) {
            return false;
        }
        $snapshot = json_decode($catalog->snapshot, true);
        foreach ($snapshot['offers'] ?? [] as $current) {
            if (($current['providerId'] ?? null) === $offer['providerId'] && ($current['provinceId'] ?? null) === ($offer['provinceId'] ?? null) && ($current['type'] ?? null) === $offer['type'] && ($current['cost'] ?? null) === $offer['cost'] && ($current['available'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    public function decorate(array &$s): void
    {
        if (! config('digital.purchases_enabled') && ! DigitalManualPayments::enabled('rabiaa')) {
            return;
        }
        $providers = app(DigitalProviders::class);
        $statuses = collect($providers->status())->keyBy('id');
        foreach (DB::table('digital_offers')->where('active', true)->get() as $m) {
            if (! config('digital.purchases_enabled') && $m->connection !== 'rabiaa') {
                continue;
            }
            if (! ($statuses[$m->connection]['configured'] ?? false)) {
                continue;
            }
            $offer = json_decode($m->snapshot, true);
            if (! $offer['available']) {
                continue;
            }
            $catalog = $providers->catalogRecord($m->connection, $offer['provinceId'] ?? null);
            if (! $this->currentOffer($catalog, $offer, ! ($m->connection === 'rabiaa' && DigitalManualPayments::enabled('rabiaa')))) {
                continue;
            }
            $premium = json_decode(DB::table('digital_connections')->where('provider', 'rabiaa')->value('premium_provinces') ?? '[]', true);
            if ($offer['type'] === 'premium' && ! $premium) {
                continue;
            }
            foreach ($s['products'] as &$p) {
                if ((string) $p['id'] === $m->product_id && isset($p['values'][$m->denom_index])) {
                    $p['digitalOffers'][(int) $m->denom_index] = ['manualPayment' => $m->connection === 'rabiaa' && DigitalManualPayments::enabled('rabiaa'), 'provider' => $m->connection, 'type' => $offer['type'], 'provinceId' => $offer['provinceId'], 'title' => $offer['title'], 'subscriberProvinces' => $offer['type'] === 'premium' ? $premium : []];
                    $p['prices'][(int) $m->denom_index] = (float) $m->retail;
                    $p['availability'][(int) $m->denom_index] = $offer['cost'] !== null && ! BigDecimal::of((string) $m->retail)->isLessThan($offer['cost']);
                }
            }unset($p);
        }
    }

    public static function input(array $b, array $offer, string $provider): array
    {
        $input = $b['digital'] ?? [];
        if (! is_array($input)) {
            throw new ApiError('بيانات المستفيد غير صحيحة', 422);
        }
        if ($provider === 'topup') {
            $phone = DigitalProviders::mobile($input['mobile'] ?? null);
            if ($phone !== DigitalProviders::mobile($input['confirmMobile'] ?? $input['confirm_mobile'] ?? null)) {
                throw new ApiError('تأكد من تطابق رقمي المستفيد', 422);
            }

            return ['mobile' => $phone, 'type' => $offer['type'], 'category' => $offer['providerId']];
        }
        $data = ['catalogId' => $offer['providerId']];
        if ($offer['type'] === 'premium') {
            foreach (['firstName', 'lastName', 'phoneNumber', 'beinProvinceId'] as $key) {
                $data[$key] = CatalogEditor::text($input[$key] ?? '', $key === 'address' ? 300 : 100);
                if (! $data[$key]) {
                    throw new ApiError('أكمل بيانات مشترك Premium', 422);
                }
            }
            $data['phone'] = DigitalProviders::mobile($data['phoneNumber']);
            unset($data['phoneNumber']);
            if ($data['phone'] !== DigitalProviders::mobile($input['confirmMobile'] ?? $input['confirm_mobile'] ?? null)) {
                throw new ApiError('تأكد من رقم المشترك بعد نافذة التأكيد', 422);
            }
            if (! preg_match('/^[1-9][0-9]{0,8}$/D', $data['beinProvinceId'])) {
                throw new ApiError('اختر محافظة المشترك', 422);
            }
        }

        return $data;
    }

    public function reserve(array $s, array $customer, array $b, array $p, int $denom, object $mapping): array
    {
        if (($b['payment'] ?? null) === 'Manual' && (! DigitalManualPayments::enabled($mapping->connection) || ($mapping->connection === 'topup' && ! isset($b['publicationId'])))) {
            throw new ApiError('الدفع اليدوي غير متاح لهذه الخدمة', 409);
        }
        if (($b['quantity'] ?? null) !== 1) {
            throw new ApiError('الخدمة الرقمية تُطلب لمستفيد واحد في كل طلب', 422);
        }
        $provider = app(DigitalProviders::class);
        $c = $provider->settings($mapping->connection);
        $offer = json_decode($mapping->snapshot, true);
        $catalog = $provider->catalogRecord($mapping->connection, $offer['provinceId'] ?? null);
        if (! $this->currentOffer($catalog, $offer, $b['payment'] !== 'Manual')) {
            throw new ApiError('فئات الشركة تحتاج تحديثاً قبل طلب الخدمة', 409);
        }
        if (! $offer['available'] || ! $offer['cost']) {
            throw new ApiError('الفئة الرقمية غير متوفرة', 409);
        }
        $input = self::input($b, $offer, $mapping->connection);
        if (BigDecimal::of((string) $mapping->retail)->isLessThan($offer['cost'])) {
            throw new ApiError('ارتفع سعر الشركة فوق سعر البيع؛ حدّث التسعير قبل البيع', 409);
        }
        $amount = StockOrders::money((string) $mapping->retail);
        if ($mapping->connection === 'rabiaa' && $b['payment'] === 'Manual' && StockOrders::money($b['quotedAmount'] ?? null) !== $amount) {
            throw new ApiError('سعر البيع تغير؛ أعد فتح الفئة لتأكيد المبلغ', 409);
        }
        $id = 'ORDER-'.Str::uuid();
        $o = ['id' => $id, 'reference' => $b['reference'], 'customerId' => $customer['id'], 'customer' => $customer['name'], 'phone' => $customer['phone'], 'email' => $customer['email'], 'productId' => $p['id'], 'name' => $p['name'], 'unit' => $p['unit'], 'value' => $p['values'][$denom], 'denomIndex' => $denom, 'quantity' => 1, 'price' => (float) $amount, 'createdAt' => now()->toIso8601String(), 'channel' => in_array($b['channel'] ?? null, ['web', 'app'], true) ? $b['channel'] : 'unknown', 'date' => now('Asia/Baghdad')->toDateString(), 'kind' => $customer['kind'], 'status' => 'قيد المراجعة', 'payment' => $b['payment'], 'digitalProvider' => $mapping->connection, 'recipient' => $input['mobile'] ?? ($input['phone'] ?? '')];
        $o['saleSnapshot'] = SaleSnapshots::capture($p, $denom, 1, $amount, $offer);
        $o['saleSnapshot']['companyName'] = collect($s['companies'])->firstWhere('id', $p['manufacturerId'] ?? '')['name'] ?? null;
        $o['saleSnapshot']['companyPath'] = array_column(CompanyHierarchy::path($s['companies'], $p['manufacturerId'] ?? ''), 'id');
        array_unshift($s['orders'], $o);
        if (isset($b['publicationId'])) {
            $s['orders'][0]['publicationId'] = $b['publicationId'];
        }
        app(Store::class)->save($s);
        $expires = $b['payment'] === 'Manual' ? now()->addDay()->timestamp : now()->addMinutes(15)->timestamp;
        DB::table('order_fulfillments')->insert(['order_id' => $id, 'state' => 'reserved', 'provider' => $b['payment'], 'amount' => $amount, 'currency' => 'IQD', 'quantity' => 1, 'expires' => $expires]);
        DB::table('digital_executions')->insert(['order_id' => $id, 'provider' => $mapping->connection, 'offer_id' => $mapping->id, 'connection_version' => $c['version'], 'state' => 'awaiting_payment', 'journal_version' => 1, 'tracking_version' => 1, 'encrypted_input' => Crypt::encryptString(Store::json(['input' => $input, 'offer' => $offer, 'retail' => $amount, 'customerId' => $customer['id'], 'customerName' => $customer['name']])), 'request_hash' => hash('sha256', Store::json($input)), 'quoted_cost' => $offer['cost'], 'updated' => time()]);
        $s['orders'][0] += ['paymentStatus' => 'pending', 'fulfillmentStatus' => 'reserved', 'codesAvailable' => false, 'reservationExpiresAt' => $expires * 1000];
        app(Store::class)->event($s, 'طلب خدمة رقمية بانتظار الدفع');
        app(Store::class)->save($s);
        app(CustomerNotifications::class)->send($customer['id'], 'تم إضافة طلبك', 'طلب الخدمة بانتظار تأكيد الدفع', 'order-'.$id);

        return ['order' => $s['orders'][0], 'created' => true];
    }

    public function sameRequest(string $id, array $b): void
    {
        $r = DB::table('digital_executions')->where('order_id', $id)->first();
        if (! $r) {
            return;
        }
        $saved = json_decode(Crypt::decryptString($r->encrypted_input), true);
        $input = self::input($b, $saved['offer'], $r->provider);
        if (! hash_equals($r->request_hash, hash('sha256', Store::json($input)))) {
            throw new ApiError('مرجع الطلب مستخدم لمستفيد مختلف', 409);
        }
    }

    public function executions(): array
    {
        $rows = DB::table('digital_executions')->orderByDesc('updated')->limit(100)->get();
        $dispatches = DigitalDispatches::forOrders($rows->pluck('order_id')->all());

        return $rows->map(fn ($r) => ['orderId' => $r->order_id, 'provider' => $r->provider, 'state' => $r->state, 'reference' => $r->provider_reference, 'quotedCost' => $r->quoted_cost, 'actualCost' => $r->actual_cost, 'reason' => $r->reason, 'dispatches' => $dispatches[$r->order_id] ?? [], 'dispatchState' => DigitalDispatches::state($r, $dispatches[$r->order_id] ?? []), 'updatedAt' => date(DATE_ATOM, $r->updated)])->all();
    }

    private function stage(string $id, string $state, array $evidence): void
    {
        DB::transaction(function () use ($id, $state, $evidence) {
            app(Store::class)->read(true);
            $r = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            if (app(DigitalProviders::class)->settings($r->provider)['version'] !== (int) $r->connection_version) {
                throw new ApiError('connection_changed', 409);
            }
            if (! in_array($r->state, ['processing', 'hold_started', 'held'], true)) {
                throw new ApiError('تنفيذ الطلب تغير', 409);
            }
            DB::table('digital_executions')->where('order_id', $id)->update([...($state === 'purchase_started' ? ['dispatch_started' => time()] : []), 'state' => $state, 'encrypted_evidence' => Crypt::encryptString(Store::json($evidence)), 'updated' => time()]);
        });
    }

    private function result(string $provider, array $data, array $saved, array $evidence): array
    {
        if ($provider === 'topup') {
            $result = TopupResult::classify($data, $saved['input']['mobile']);
            if ($result['status'] !== 'succeeded') {
                throw new ApiError($result['status'] === 'failed' ? 'provider_rejected' : 'provider_result_uncertain', 409);
            }

            return ['reference' => $result['reference'], 'cost' => null, 'costBasis' => 'unknown', 'codes' => []];
        }
        $d = $data['data'] ?? null;
        $hold = $evidence['hold'] ?? [];
        if (! is_array($d) || (string) ($d['cardId'] ?? '') !== (string) ($hold['cardId'] ?? '') || ! preg_match('/^[1-9][0-9]{0,99}$/D', (string) ($d['transactionId'] ?? '')) || ! is_string($d['code'] ?? null) || $d['code'] === '' || strlen($d['code']) > 2000 || ! is_string($d['serialNumber'] ?? null) || strlen($d['serialNumber']) > 2000) {
            throw new ApiError('purchase_evidence_incomplete', 409);
        }
        $review = $saved['offer']['type'] === 'premium' && ($d['beinStatus'] ?? null) !== 'success';

        // A zero/missing provider price is not proof of a free card.
        try {
            $cost = isset($d[($saved['offer']['priceBasis'] ?? 'vendor_price') === 'public_price' ? 'publicPrice' : 'vendorPrice']) ? StockOrders::money((string) $d[($saved['offer']['priceBasis'] ?? 'vendor_price') === 'public_price' ? 'publicPrice' : 'vendorPrice']) : null;
        } catch (ApiError) {
            $cost = null;
        }
        $cost = $cost !== null && BigDecimal::of($cost)->isPositive() ? $cost : null;

        return ['review' => $review, 'reference' => (string) $d['transactionId'], 'cost' => $cost, 'costBasis' => 'provider_response', 'codes' => [['code' => $d['code'], 'serial' => $d['serialNumber']]]];
    }

    private function finish(string $id, ?array $result, string $reason = ''): void
    {
        DB::transaction(function () use ($id, $result, $reason) {
            $store = app(Store::class);
            $s = $store->read(true);
            $r = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            if (in_array($r->state, ['delivered', 'refunded'], true)) {
                return;
            }
            if (DB::table('digital_refund_entries')->where('order_id', $id)->exists()) {
                return; // A late response must not deliver after a customer repayment began.
            }
            $fulfillment = DB::table('order_fulfillments')->where('order_id', $id)->lockForUpdate()->first();
            $priorEvidence = $r->encrypted_evidence ? json_decode(Crypt::decryptString($r->encrypted_evidence), true) : [];
            if (($priorEvidence['companyRefund']['confirmed'] ?? false) === true) {
                return;
            }
            if (! $fulfillment || $fulfillment->payment_status !== 'confirmed') {
                throw new ApiError('لم يثبت دفع الطلب', 409);
            }
            $i = array_search($id, array_column($s['orders'], 'id'), true);
            if ($i === false) {
                throw new \RuntimeException('Missing order');
            }
            $delivered = $result && ! ($result['review'] ?? false);
            if ($result && ! $delivered) {
                $reason = 'premium_delivery_uncertain';
            }
            $state = $delivered ? 'delivered' : ($reason === 'provider_rejected' ? 'failed' : 'review');
            $changes = ['state' => $state, 'reason' => $reason ?: null, 'updated' => time()];
            if ($result) {
                $e = $r->encrypted_evidence ? json_decode(Crypt::decryptString($r->encrypted_evidence), true) : [];
                $e['delivery'] = $result;
                $changes += ['provider_reference' => $result['reference'], 'actual_cost' => $result['cost'], 'encrypted_evidence' => Crypt::encryptString(Store::json($e))];
            }
            DB::table('digital_executions')->where('order_id', $id)->update($changes);
            DB::table('order_fulfillments')->where('order_id', $id)->where('payment_status', 'confirmed')->update(['state' => $state, 'review_reason' => $reason ?: null, ...$delivered ? ['delivered_at' => time()] : []]);
            $s['orders'][$i] = [...$s['orders'][$i], 'fulfillmentStatus' => $state, 'codesAvailable' => (bool) ($delivered && $r->provider === 'rabiaa'), 'paymentStatus' => 'confirmed', 'status' => $delivered ? 'مكتمل' : 'قيد المراجعة', 'paymentReviewReason' => $reason ?: null];
            if ($result) {
                $s['orders'][$i] += ['providerReference' => $result['reference'], 'costBasis' => $result['costBasis']];
                $s['orders'][$i]['costTotal'] = $result['cost'];
                $s['orders'][$i]['profitBeforeFees'] = $result['cost'] !== null ? (string) BigDecimal::of($fulfillment->amount)->minus($result['cost']) : null;
            }
            $store->event($s, $delivered ? 'تسليم خدمة رقمية: '.$id : 'خدمة رقمية تحتاج مراجعة: '.$id);
            $store->save($s);
            app(CustomerNotifications::class)->send($s['orders'][$i]['customerId'], 'تحديث طلب الخدمة', $delivered ? 'تم تنفيذ طلب الخدمة' : 'طلبك قيد مراجعة التنفيذ', 'digital-'.$id.'-'.$state);
        });
    }

    public function run(): array
    {
        if (! config('digital.purchases_enabled') && ! DigitalManualPayments::enabled('topup') && ! DigitalManualPayments::enabled('rabiaa')) {
            return ['processed' => 0];
        }
        // Crash recovery never replays a financial POST, even after a worker is restarted.
        foreach (DB::table('digital_executions')->whereIn('state', ['processing', 'hold_started', 'held', 'purchase_started'])->where('updated', '<', time() - 180)->pluck('order_id') as $id) {
            $this->finish($id, null, 'interrupted_execution');
        }
        $count = 0;
        foreach (DB::table('digital_executions')->where('state', 'queued')->orderBy('updated')->limit(10)->pluck('order_id') as $id) {
            $this->execute($id);
            $count++;
        }

        return ['processed' => $count];
    }

    public function execute(string $id): void
    {
        if (! config('digital.purchases_enabled') && ! DigitalManualPayments::approved($id, DB::table('digital_executions')->where('order_id', $id)->value('provider') ?? '')) {
            return;
        }
        $r = DB::transaction(function () use ($id) {
            app(Store::class)->read(true);
            $r = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            $f = DB::table('order_fulfillments')->where('order_id', $id)->first();
            if (! $r || $r->state !== 'queued' || ! $f || $f->payment_status !== 'confirmed' || $f->state !== 'processing') {
                return null;
            }
            DB::table('digital_executions')->where('order_id', $id)->update(['state' => 'processing', 'journal_version' => 1, 'tracking_version' => 1, 'updated' => time()]);

            return $r;
        });
        if (! $r) {
            return;
        }
        $evidence = [];
        try {
            $providers = app(DigitalProviders::class);
            $c = $providers->settings($r->provider);
            if ($c['version'] !== (int) $r->connection_version) {
                throw new ApiError('connection_changed', 409);
            }
            $saved = json_decode(Crypt::decryptString($r->encrypted_input), true);
            $offer = $saved['offer'];
            $catalog = $providers->catalog($r->provider, $offer['provinceId']);
            $fresh = collect($catalog['offers'])->first(fn ($o) => $o['providerId'] === $offer['providerId'] && $o['type'] === $offer['type'] && ($o['provinceId'] ?? null) === ($offer['provinceId'] ?? null));
            $mapping = DB::table('digital_offers')->where('id', $r->offer_id)->first();
            $mapping ??= DB::table('digital_publications')->where('id', $r->offer_id)->first();
            if (! $fresh || ! $fresh['available'] || $fresh['cost'] !== StockOrders::money((string) $r->quoted_cost) || $fresh['type'] !== $offer['type'] || ! $mapping?->active || StockOrders::money((string) $mapping->retail) !== $saved['retail']) {
                throw new ApiError('quote_changed', 409);
            }
            if ($r->provider === 'topup') {
                $eligible = $providers->request('topup', '/api/v1/checkEligibility', $saved['input'], true);
                if (! is_array($eligible) || ($eligible['status'] ?? null) !== true) {
                    throw new ApiError('eligibility_not_confirmed', 409);
                }
                $this->stage($id, 'purchase_started', []);
                $response = $providers->request('topup', '/api/v1/transactions', $saved['input'] + ['user_id' => $saved['customerId'], 'user_name' => $saved['customerName'], 'agent_name' => 'Fatya'], true, $id);
            } else {
                if ($offer['type'] === 'premium') {
                    $provinces = $providers->provinces(true);
                    if (! collect($provinces)->contains('id', $saved['input']['beinProvinceId'])) {
                        throw new ApiError('invalid_subscriber_province', 409);
                    }
                }
                $this->stage($id, 'hold_started', []);
                $hold = $providers->request('rabiaa', '/vendor/hold', $saved['input'], true, $id)['data'] ?? null;
                if (! is_array($hold) || ! preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/iD', (string) ($hold['holdToken'] ?? '')) || ! preg_match('/^[1-9][0-9]{0,99}$/D', (string) ($hold['cardId'] ?? '')) || ! is_int($hold['expiresInMinutes'] ?? null) || $hold['expiresInMinutes'] < 1 || $hold['expiresInMinutes'] > 60 || ! is_string($hold['heldAt'] ?? null)) {
                    throw new ApiError('invalid_hold_evidence', 409);
                }
                $heldAt = CarbonImmutable::parse($hold['heldAt']);
                if ($heldAt->isAfter(now()->addMinute()) || $heldAt->addMinutes($hold['expiresInMinutes'])->isBefore(now()) || StockOrders::money((string) ($hold[($offer['priceBasis'] ?? 'vendor_price') === 'public_price' ? 'publicPrice' : 'vendorPrice'] ?? '')) !== StockOrders::money((string) $r->quoted_cost)) {
                    throw new ApiError('hold_price_or_expiry_changed', 409);
                }
                $evidence = ['hold' => $hold];
                $this->stage($id, 'held', $evidence);
                $this->stage($id, 'purchase_started', $evidence);
                $response = $providers->request('rabiaa', '/vendor/purchase', ['holdToken' => $hold['holdToken']], true, $id);
            }
            if (! is_array($response)) {
                throw new ApiError('invalid_provider_response', 409);
            }
            $evidence['response'] = $response;
            DB::table('digital_executions')->where('order_id', $id)->update(['response_received' => time(), 'encrypted_evidence' => Crypt::encryptString(Store::json($evidence)), 'updated' => time()]);
            $this->finish($id, $this->result($r->provider, $response, $saved, $evidence));
        } catch (\Throwable $error) {
            // Only our own allowlisted reason codes reach the administrator.
            $reason = $error instanceof ProviderTransportError ? $error->reason : null;
            if ($error instanceof ApiError && in_array($error->getMessage(), ['connection_changed', 'quote_changed', 'eligibility_not_confirmed', 'invalid_subscriber_province', 'invalid_hold_evidence', 'hold_price_or_expiry_changed', 'invalid_provider_response', 'provider_rejected', 'provider_result_uncertain', 'recipient_mismatch', 'missing_provider_reference', 'purchase_evidence_incomplete', 'premium_delivery_uncertain', 'financial_dispatch_already_recorded', 'financial_dispatch_not_authorized'], true)) {
                $reason = $error->getMessage();
            }
            $this->finish($id, null, $reason ?? 'provider_result_requires_review');
        }
    }

    public function receipt(string $id, bool $allowReview = false): array
    {
        $r = DB::table('digital_executions')->where('order_id', $id)->first();
        if (! $r || ! in_array($r->state, $allowReview ? ['delivered', 'review'] : ['delivered'], true) || ! $r->encrypted_evidence) {
            throw new ApiError('التسليم يحتاج مراجعة', 409);
        }
        $evidence = json_decode(Crypt::decryptString($r->encrypted_evidence), true);
        $delivery = $evidence['delivery'] ?? null;
        if (! $delivery || ($evidence['companyRefund']['confirmed'] ?? false) || ! DB::table('order_fulfillments')->where('order_id', $id)->where('payment_status', 'confirmed')->exists()) {
            throw new ApiError('الإيصال غير متاح أو استُرجع مبلغ البطاقة', 409);
        }

        return ['orderId' => $id, 'state' => $r->state, 'activationPending' => $r->state === 'review', 'reference' => $delivery['reference'], 'codes' => $delivery['codes']];
    }

    public function reconcile(array $b): array
    {
        $r = DB::table('digital_executions')->where('order_id', $b['orderId'] ?? '')->first();
        if (! $r || ! in_array($r->state, ['review', 'delivered', 'refunded'], true)) {
            throw new ApiError('الطلب غير متاح للمراجعة', 409);
        }
        if ($r->state === 'refunded') {
            return ['state' => 'refunded'];
        }
        $refundCheck = ($b['refundCheck'] ?? false) === true;
        $e = $r->encrypted_evidence ? json_decode(Crypt::decryptString($r->encrypted_evidence), true) : [];
        if ($r->state === 'delivered' && ! $refundCheck) {
            return ['state' => 'delivered', 'reference' => $r->provider_reference];
        }
        if (! $refundCheck && isset($e['response'])) {
            try {
                $saved = json_decode(Crypt::decryptString($r->encrypted_input), true);
                $this->finish($r->order_id, $this->result($r->provider, $e['response'], $saved, $e));

                if (DB::table('digital_executions')->where('order_id', $r->order_id)->value('state') === 'delivered') {
                    return ['state' => 'delivered'];
                }
            } catch (\Throwable) {
            }
        }
        if ($r->provider === 'topup') {
            return ['state' => 'review', 'message' => 'لا يوفر المزود استعلام حالة مستقلاً؛ راجع مرجع الشركة دون إعادة شراء'];
        }
        $hold = $e['hold'] ?? null;
        if (! $hold) {
            return ['state' => 'review', 'message' => 'لا يوجد حجز محفوظ؛ يلزم الرجوع للشركة'];
        }
        if (app(DigitalProviders::class)->settings('rabiaa')['version'] !== (int) $r->connection_version) {
            throw new ApiError('ربط الشركة تغير؛ راجع العملية مع حسابها الأصلي', 409);
        }
        $page = 1;
        $rows = [];
        do {
            $data = app(DigitalProviders::class)->request('rabiaa', '/vendor/transactions', ['page' => $page, 'pageSize' => 100, 'dateFrom' => CarbonImmutable::parse($hold['heldAt'])->utc()->toDateString(), 'dateTo' => now()->utc()->toDateString()]);
            $batch = $data['data'] ?? null;
            $last = $data['meta']['pagination']['pageCount'] ?? null;
            if (! is_array($batch) || ! array_is_list($batch) || count($batch) > 100 || ! is_int($last) || $last < 0 || $last > 100 || $last === 0 && $batch !== []) {
                throw new ApiError('سجل الرابعة يتجاوز نطاق التحقق؛ يلزم مراجعة الشركة', 502);
            }
            $rows = array_merge($rows, $batch);
            $page++;
        } while ($page <= $last);
        $saved = json_decode(Crypt::decryptString($r->encrypted_input), true);
        $matches = array_values(array_filter($rows, fn ($row) => is_array($row) && ($row['type'] ?? '') === 'purchase' && (string) ($row['cardId'] ?? '') === (string) $hold['cardId'] && (string) ($row['catalogId'] ?? '') === $saved['offer']['providerId'] && is_string($row['createdAt'] ?? null) && CarbonImmutable::parse($row['createdAt'])->greaterThanOrEqualTo(CarbonImmutable::parse($hold['heldAt'])) && (! $r->provider_reference || (string) ($row['id'] ?? '') === $r->provider_reference)));
        if (count($matches) > 1) {
            throw new ApiError('يوجد أكثر من شراء مطابق؛ يلزم مراجعة الشركة', 409);
        }
        if ($matches) {
            $purchase = $matches[0];
            if (! preg_match('/^[1-9][0-9]{0,99}$/D', (string) ($purchase['id'] ?? ''))) {
                throw new ApiError('مرجع شراء الشركة غير صحيح', 502);
            }
            $priceKey = ($saved['offer']['priceBasis'] ?? 'vendor_price') === 'public_price' ? 'publicPrice' : 'vendorPrice';
            $cost = StockOrders::money((string) ($purchase[$priceKey] ?? ''));
            if ($r->actual_cost !== null && StockOrders::money((string) $r->actual_cost) !== $cost) {
                throw new ApiError('كلفة الشركة لا تطابق الأدلة المحفوظة', 409);
            }
            DB::transaction(function () use ($r, $purchase, $cost) {
                app(Store::class)->read(true);
                $current = DB::table('digital_executions')->where('order_id', $r->order_id)->lockForUpdate()->first();
                if (! in_array($current->state, ['review', 'delivered'], true)) {
                    throw new ApiError('تغيرت حالة التنفيذ؛ حدّث السجل', 409);
                }
                $evidence = $current->encrypted_evidence ? json_decode(Crypt::decryptString($current->encrypted_evidence), true) : [];
                $evidence['companyPurchase'] = ['reference' => (string) $purchase['id'], 'cost' => $cost, 'beinStatus' => $purchase['beinStatus'] ?? null];
                DB::table('digital_executions')->where('order_id', $r->order_id)->update(['provider_reference' => (string) $purchase['id'], 'actual_cost' => $cost, 'encrypted_evidence' => Crypt::encryptString(Store::json($evidence)), 'updated' => time()]);
            });
            if (isset($purchase['refundIds']) && ! is_array($purchase['refundIds'])) {
                throw new ApiError('سجل استرجاع الشركة غير مفهوم', 502);
            }
            $refundFound = false;
            foreach ($rows as $refund) {
                if (is_array($refund) && ($refund['type'] ?? '') === 'refund' && in_array($refund['id'] ?? null, $purchase['refundIds'] ?? [], true) && (string) ($refund['refundOfId'] ?? '') === (string) $purchase['id'] && (string) ($refund['cardId'] ?? '') === (string) $hold['cardId']) {
                    $refundCost = BigDecimal::of((string) ($refund[$priceKey] ?? 'invalid'))->abs();
                    if ($refundCost->isEqualTo($cost) && ($priceKey === 'publicPrice' || str_starts_with((string) ($refund[$priceKey] ?? ''), '-'))) {
                        $refundFound = true;
                    }
                }
            }
            if ($refundFound) {
                DB::transaction(function () use ($r, $purchase, $cost) {
                    $store = app(Store::class);
                    $state = $store->read(true);
                    $current = DB::table('digital_executions')->where('order_id', $r->order_id)->lockForUpdate()->first();
                    if (! in_array($current->state, ['review', 'delivered'], true)) {
                        throw new ApiError('حالة الطلب تغيرت', 409);
                    }
                    $evidence = json_decode(Crypt::decryptString($current->encrypted_evidence), true);
                    $evidence['companyRefund'] = ['confirmed' => true, 'purchaseReference' => (string) $purchase['id'], 'cost' => $cost, 'checkedAt' => now()->toIso8601String()];
                    DB::table('digital_executions')->where('order_id', $r->order_id)->update(['state' => 'review', 'reason' => 'company_refund_pending_settlement', 'encrypted_evidence' => Crypt::encryptString(Store::json($evidence)), 'updated' => time()]);
                    DB::table('order_fulfillments')->where('order_id', $r->order_id)->update(['state' => 'review', 'review_reason' => 'company_refund_pending_settlement']);
                    $index = array_search($r->order_id, array_column($state['orders'], 'id'), true);
                    if ($index !== false) {
                        $state['orders'][$index] = [...$state['orders'][$index], 'fulfillmentStatus' => 'review', 'codesAvailable' => false, 'status' => 'قيد المراجعة', 'profitBeforeFees' => null, 'paymentReviewReason' => 'company_refund_pending_settlement'];
                        $store->save($state);
                    }
                    ProcurementAudit::record('digital.company_refund_confirmed', $r->order_id, ['reference' => (string) $purchase['id']]);
                });
            }
            if (! $refundFound && empty($purchase['refundIds']) && isset($e['response']['data']['code']) && ($saved['offer']['type'] !== 'premium' || ($purchase['beinStatus'] ?? '') === 'success')) {
                $response = $e['response'];
                $response['data']['beinStatus'] = $purchase['beinStatus'] ?? null;
                $this->finish($r->order_id, $this->result('rabiaa', $response, $saved, $e));

                return ['state' => 'delivered', 'companyPurchaseFound' => true];
            }

            return ['state' => DB::table('digital_executions')->where('order_id', $r->order_id)->value('state'), 'companyPurchaseFound' => true, 'companyRefundFound' => $refundFound, 'reference' => (string) $purchase['id'], 'receiptMissing' => ! isset($e['delivery']), 'message' => $refundFound ? 'تأكد استرجاع الشركة؛ راجع تسوية دفعة الزبون الأصلية دون شراء جديد' : 'أكدت الشركة الشراء. السجل لا يعيد PIN ضائعاً؛ راجع الشركة دون شراء جديد'];
        }

        return ['state' => 'review', 'companyPurchaseFound' => false, 'pageCount' => $last, 'message' => 'لم يظهر شراء مطابق؛ هذا لا يثبت الفشل ولا يسمح بإعادة الشراء'];
    }
}
