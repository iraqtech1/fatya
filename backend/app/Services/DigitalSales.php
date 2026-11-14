<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Exceptions\ProviderTransportError;
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
        if (! config('digital.purchases_enabled')) {
            return null;
        }

        return DB::table('digital_offers')->where('product_id', $product)->where('denom_index', $denom)->where('active', true)->first();
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
        if (! config('digital.purchases_enabled')) {
            return;
        }
        $providers = app(DigitalProviders::class);
        $statuses = collect($providers->status())->keyBy('id');
        foreach (DB::table('digital_offers')->where('active', true)->get() as $m) {
            if (! ($statuses[$m->connection]['configured'] ?? false)) {
                continue;
            }
            $offer = json_decode($m->snapshot, true);
            if (! $offer['available']) {
                continue;
            }
            $catalog = $providers->catalogRecord($m->connection, $offer['provinceId'] ?? null);
            if (! $this->currentOffer($catalog, $offer)) {
                continue;
            }
            $premium = json_decode(DB::table('digital_connections')->where('provider', 'rabiaa')->value('premium_provinces') ?? '[]', true);
            if ($offer['type'] === 'premium' && ! $premium) {
                continue;
            }
            foreach ($s['products'] as &$p) {
                if ((string) $p['id'] === $m->product_id && isset($p['values'][$m->denom_index])) {
                    $p['digitalOffers'][(int) $m->denom_index] = ['provider' => $m->connection, 'type' => $offer['type'], 'provinceId' => $offer['provinceId'], 'title' => $offer['title'], 'subscriberProvinces' => $offer['type'] === 'premium' ? $premium : []];
                    $p['prices'][(int) $m->denom_index] = (float) $m->retail;
                    $p['availability'][(int) $m->denom_index] = true;
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
            if ($phone !== DigitalProviders::mobile($input['confirmMobile'] ?? null)) {
                throw new ApiError('تأكد من تطابق رقمي المستفيد', 422);
            }

            return ['mobile' => $phone, 'type' => $offer['type'], 'category' => $offer['providerId']];
        }
        $data = ['catalogId' => $offer['providerId']];
        if ($offer['type'] === 'premium') {
            foreach (['firstName', 'lastName', 'phoneNumber', 'address', 'beinProvinceId'] as $key) {
                $data[$key] = CatalogEditor::text($input[$key] ?? '', $key === 'address' ? 300 : 100);
                if (! $data[$key]) {
                    throw new ApiError('أكمل بيانات مشترك Premium', 422);
                }
            }
            $data['phoneNumber'] = DigitalProviders::mobile($data['phoneNumber']);
            if (! preg_match('/^[1-9][0-9]{0,8}$/D', $data['beinProvinceId'])) {
                throw new ApiError('اختر محافظة المشترك', 422);
            }
        }

        return $data;
    }

    public function reserve(array $s, array $customer, array $b, array $p, int $denom, object $mapping): array
    {
        if (($b['payment'] ?? null) === 'Manual' && (! config('digital.manual_topup_enabled') || ! isset($b['publicationId']) || $mapping->connection !== 'topup')) {
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
        $amount = StockOrders::money((string) $mapping->retail);
        $id = 'ORDER-'.Str::uuid();
        $o = ['id' => $id, 'reference' => $b['reference'], 'customerId' => $customer['id'], 'customer' => $customer['name'], 'phone' => $customer['phone'], 'email' => $customer['email'], 'productId' => $p['id'], 'name' => $p['name'], 'unit' => $p['unit'], 'value' => $p['values'][$denom], 'denomIndex' => $denom, 'quantity' => 1, 'price' => (float) $amount, 'createdAt' => now()->toIso8601String(), 'channel' => in_array($b['channel'] ?? null, ['web', 'app'], true) ? $b['channel'] : 'unknown', 'date' => now('Asia/Baghdad')->toDateString(), 'kind' => $customer['kind'], 'status' => 'قيد المراجعة', 'payment' => $b['payment'], 'digitalProvider' => $mapping->connection, 'recipient' => $input['mobile'] ?? ($input['phoneNumber'] ?? '')];
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
        DB::table('digital_executions')->insert(['order_id' => $id, 'provider' => $mapping->connection, 'offer_id' => $mapping->id, 'connection_version' => $c['version'], 'state' => 'awaiting_payment', 'journal_version' => 1, 'encrypted_input' => Crypt::encryptString(Store::json(['input' => $input, 'offer' => $offer, 'retail' => $amount, 'customerId' => $customer['id'], 'customerName' => $customer['name']])), 'request_hash' => hash('sha256', Store::json($input)), 'quoted_cost' => $offer['cost'], 'updated' => time()]);
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

        return $rows->map(fn ($r) => ['orderId' => $r->order_id, 'provider' => $r->provider, 'state' => $r->state, 'reference' => $r->provider_reference, 'quotedCost' => $r->quoted_cost, 'actualCost' => $r->actual_cost, 'reason' => $r->reason, 'dispatches' => $dispatches[$r->order_id] ?? [], 'dispatchState' => $r->journal_version === null ? 'legacy_unknown' : ($r->state === 'delivered' ? 'success_confirmed' : (empty($dispatches[$r->order_id]) ? (in_array($r->state, ['review', 'failed'], true) ? 'not_sent' : 'not_requested') : 'uncertain')), 'updatedAt' => date(DATE_ATOM, $r->updated)])->all();
    }

    private function stage(string $id, string $state, array $evidence): void
    {
        DB::transaction(function () use ($id, $state, $evidence) {
            app(Store::class)->read(true);
            $r = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            if (! in_array($r->state, ['processing', 'hold_started', 'held'], true)) {
                throw new ApiError('تنفيذ الطلب تغير', 409);
            }
            DB::table('digital_executions')->where('order_id', $id)->update(['state' => $state, 'encrypted_evidence' => Crypt::encryptString(Store::json($evidence)), 'updated' => time()]);
        });
    }

    private function result(string $provider, array $data, array $saved, array $evidence): array
    {
        if ($provider === 'topup') {
            if (! in_array(strtolower((string) ($data['status'] ?? '')), ['success', 'succeed'], true)) {
                throw new ApiError('provider_result_uncertain', 409);
            }
            $d = $data['data'] ?? $data;
            if (! is_array($d) || (isset($data['data']) && ! in_array(strtolower((string) ($d['status'] ?? '')), ['success', 'succeed'], true))) {
                throw new ApiError('provider_result_uncertain', 409);
            }
            if (isset($d['logicalResource']['value']) && DigitalProviders::mobile((string) $d['logicalResource']['value']) !== $saved['input']['mobile']) {
                throw new ApiError('recipient_mismatch', 409);
            }
            $ref = $d['id'] ?? null;
            if (! is_scalar($ref) || ! preg_match('/^[a-zA-Z0-9_-]{1,150}$/D', (string) $ref)) {
                throw new ApiError('missing_provider_reference', 409);
            }

            return ['reference' => (string) $ref, 'cost' => null, 'costBasis' => 'unknown', 'codes' => []];
        }
        $d = $data['data'] ?? null;
        $hold = $evidence['hold'] ?? [];
        if (! is_array($d) || (string) ($d['cardId'] ?? '') !== (string) ($hold['cardId'] ?? '') || ! preg_match('/^[1-9][0-9]{0,99}$/D', (string) ($d['transactionId'] ?? '')) || ! is_string($d['code'] ?? null) || $d['code'] === '' || strlen($d['code']) > 2000 || ! is_string($d['serialNumber'] ?? null) || strlen($d['serialNumber']) > 2000) {
            throw new ApiError('purchase_evidence_incomplete', 409);
        }
        if ($saved['offer']['type'] === 'premium' && ($d['beinStatus'] ?? null) !== 'success') {
            throw new ApiError('premium_delivery_uncertain', 409);
        }

        // A zero/missing provider price is not proof of a free card.
        try {
            $cost = isset($d['vendorPrice']) ? StockOrders::money((string) $d['vendorPrice']) : null;
        } catch (ApiError) {
            $cost = null;
        }
        $cost = $cost !== null && BigDecimal::of($cost)->isPositive() ? $cost : null;

        return ['reference' => (string) $d['transactionId'], 'cost' => $cost, 'costBasis' => 'provider_response', 'codes' => [['code' => $d['code'], 'serial' => $d['serialNumber']]]];
    }

    private function finish(string $id, ?array $result, string $reason = ''): void
    {
        DB::transaction(function () use ($id, $result, $reason) {
            $store = app(Store::class);
            $s = $store->read(true);
            $r = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            if ($r->state === 'delivered') {
                return;
            }
            $fulfillment = DB::table('order_fulfillments')->where('order_id', $id)->lockForUpdate()->first();
            if (! $fulfillment || $fulfillment->payment_status !== 'confirmed') {
                throw new ApiError('لم يثبت دفع الطلب', 409);
            }
            $i = array_search($id, array_column($s['orders'], 'id'), true);
            if ($i === false) {
                throw new \RuntimeException('Missing order');
            }
            $state = $result ? 'delivered' : 'review';
            $changes = ['state' => $state, 'reason' => $reason ?: null, 'updated' => time()];
            if ($result) {
                $e = $r->encrypted_evidence ? json_decode(Crypt::decryptString($r->encrypted_evidence), true) : [];
                $e['delivery'] = $result;
                $changes += ['provider_reference' => $result['reference'], 'actual_cost' => $result['cost'], 'encrypted_evidence' => Crypt::encryptString(Store::json($e))];
            }
            DB::table('digital_executions')->where('order_id', $id)->update($changes);
            DB::table('order_fulfillments')->where('order_id', $id)->where('payment_status', 'confirmed')->update(['state' => $state, 'review_reason' => $reason ?: null, ...$result ? ['delivered_at' => time()] : []]);
            $s['orders'][$i] = [...$s['orders'][$i], 'fulfillmentStatus' => $state, 'codesAvailable' => (bool) ($result && $r->provider === 'rabiaa'), 'paymentStatus' => 'confirmed', 'status' => $result ? 'مكتمل' : 'قيد المراجعة', 'paymentReviewReason' => $reason ?: null];
            if ($result) {
                $s['orders'][$i] += ['providerReference' => $result['reference'], 'costBasis' => $result['costBasis']];
                $s['orders'][$i]['costTotal'] = $result['cost'];
                $s['orders'][$i]['profitBeforeFees'] = $result['cost'] !== null ? (string) BigDecimal::of($fulfillment->amount)->minus($result['cost']) : null;
            }
            $store->event($s, $result ? 'تسليم خدمة رقمية: '.$id : 'خدمة رقمية تحتاج مراجعة: '.$id);
            $store->save($s);
            app(CustomerNotifications::class)->send($s['orders'][$i]['customerId'], 'تحديث طلب الخدمة', $result ? 'تم تنفيذ طلب الخدمة' : 'طلبك قيد مراجعة التنفيذ', 'digital-'.$id.'-'.$state);
        });
    }

    public function run(): array
    {
        if (! config('digital.purchases_enabled') && ! config('digital.manual_topup_enabled')) {
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
        if (! config('digital.purchases_enabled') && ! DigitalManualPayments::approved($id)) {
            return;
        }
        $r = DB::transaction(function () use ($id) {
            app(Store::class)->read(true);
            $r = DB::table('digital_executions')->where('order_id', $id)->lockForUpdate()->first();
            $f = DB::table('order_fulfillments')->where('order_id', $id)->first();
            if (! $r || $r->state !== 'queued' || ! $f || $f->payment_status !== 'confirmed' || $f->state !== 'processing') {
                return null;
            }
            DB::table('digital_executions')->where('order_id', $id)->update(['state' => 'processing', 'journal_version' => 1, 'updated' => time()]);

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
                if ($heldAt->isAfter(now()->addMinute()) || $heldAt->addMinutes($hold['expiresInMinutes'])->isBefore(now()) || StockOrders::money((string) ($hold['vendorPrice'] ?? '')) !== StockOrders::money((string) $r->quoted_cost)) {
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
            DB::table('digital_executions')->where('order_id', $id)->update(['encrypted_evidence' => Crypt::encryptString(Store::json($evidence)), 'updated' => time()]);
            $this->finish($id, $this->result($r->provider, $response, $saved, $evidence));
        } catch (\Throwable $error) {
            // Only our own allowlisted reason codes reach the administrator.
            $reason = $error instanceof ProviderTransportError ? $error->reason : null;
            if ($error instanceof ApiError && in_array($error->getMessage(), ['connection_changed', 'quote_changed', 'eligibility_not_confirmed', 'invalid_subscriber_province', 'invalid_hold_evidence', 'hold_price_or_expiry_changed', 'invalid_provider_response', 'provider_result_uncertain', 'recipient_mismatch', 'missing_provider_reference', 'purchase_evidence_incomplete', 'premium_delivery_uncertain', 'financial_dispatch_already_recorded', 'financial_dispatch_not_authorized'], true)) {
                $reason = $error->getMessage();
            }
            $this->finish($id, null, $reason ?? 'provider_result_requires_review');
        }
    }

    public function receipt(string $id): array
    {
        $r = DB::table('digital_executions')->where('order_id', $id)->first();
        if (! $r || $r->state !== 'delivered' || ! $r->encrypted_evidence) {
            throw new ApiError('التسليم يحتاج مراجعة', 409);
        }
        $delivery = json_decode(Crypt::decryptString($r->encrypted_evidence), true)['delivery'];

        return ['orderId' => $id, 'reference' => $delivery['reference'], 'codes' => $delivery['codes']];
    }

    public function reconcile(array $b): array
    {
        $r = DB::table('digital_executions')->where('order_id', $b['orderId'] ?? '')->first();
        if (! $r || ! in_array($r->state, ['review', 'delivered'], true)) {
            throw new ApiError('الطلب غير متاح للمراجعة', 409);
        }
        $e = $r->encrypted_evidence ? json_decode(Crypt::decryptString($r->encrypted_evidence), true) : [];
        if ($r->state === 'delivered') {
            return ['state' => 'delivered', 'reference' => $r->provider_reference];
        }
        if (isset($e['response'])) {
            try {
                $saved = json_decode(Crypt::decryptString($r->encrypted_input), true);
                $this->finish($r->order_id, $this->result($r->provider, $e['response'], $saved, $e));

                return ['state' => 'delivered'];
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
        $page = $b['page'] ?? 1;
        if (! is_int($page) || $page < 1 || $page > 100) {
            throw new ApiError('صفحة السجل غير صحيحة', 422);
        }
        $data = app(DigitalProviders::class)->request('rabiaa', '/vendor/transactions', ['page' => $page, 'pageSize' => 100, 'dateFrom' => CarbonImmutable::parse($hold['heldAt'])->utc()->toDateString(), 'dateTo' => now()->utc()->toDateString()]);
        $rows = $data['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 100) {
            throw new ApiError('سجل الشركة غير مفهوم', 502);
        }
        $saved = json_decode(Crypt::decryptString($r->encrypted_input), true);
        $matches = array_values(array_filter($rows, fn ($row) => is_array($row) && ($row['type'] ?? '') === 'purchase' && (string) ($row['cardId'] ?? '') === (string) $hold['cardId'] && (string) ($row['catalogId'] ?? '') === $saved['offer']['providerId'] && is_string($row['createdAt'] ?? null) && CarbonImmutable::parse($row['createdAt'])->greaterThanOrEqualTo(CarbonImmutable::parse($hold['heldAt']))));

        return ['state' => 'review', 'companyPurchaseFound' => count($matches) > 0, 'references' => array_map(fn ($row) => (string) ($row['id'] ?? ''), $matches), 'page' => $page, 'pageCount' => $data['meta']['pagination']['pageCount'] ?? null, 'message' => 'السجل لا يعيد PIN ضائعاً؛ لا تنشئ شراءً بديلاً تلقائياً'];
    }
}
