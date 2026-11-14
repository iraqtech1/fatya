<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Services\Captain\ActivationResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Activation is independent of purchase, fulfillment, and all financial entries. */
class RabiaaActivations
{
    private function dto(object $row): array
    {
        return ['id' => $row->id, 'order_id' => $row->order_id, 'status' => $row->status, 'message' => $row->message, 'http_status' => $row->http_status, 'created_at' => date(DATE_ATOM, $row->created), 'finished_at' => $row->finished ? date(DATE_ATOM, $row->finished) : null];
    }

    public function listing(?string $actor, int $page = 1, array $filters = []): array
    {
        if ($page < 1 || $page > 100000) {
            throw new ApiError('صفحة غير صحيحة', 422);
        }
        $query = DB::table('rabiaa_activations')->when($actor !== null, fn ($query) => $query->where('actor', $actor));
        $dates = app(InventoryReports::class)->filters(['from' => $filters['from'] ?? '', 'to' => $filters['to'] ?? '']);
        if ($dates['from']) {
            $query->where('created', '>=', app(InventoryReports::class)->boundary($dates['from']));
        }
        if ($dates['to']) {
            $query->where('created', '<', app(InventoryReports::class)->boundary($dates['to'], true));
        }
        $total = (clone $query)->count();

        return ['data' => $query->orderByDesc('created')->orderByDesc('id')->offset(($page - 1) * 25)->limit(25)->get()->map(fn ($r) => $this->dto($r) + ($actor === null ? ['actor' => $r->actor] : []))->all(), 'meta' => ['current_page' => $page, 'last_page' => max(1, (int) ceil($total / 25)), 'total' => $total]];
    }

    public function submit(string $actor, array $data, ?string $customerId = null): array
    {
        $allowed = ['idempotency_key', 'mac_address', 'active_code', 'order_id'];
        if (array_diff(array_keys($data), $allowed) || ! is_string($data['idempotency_key'] ?? null) || ! preg_match('/\A[a-zA-Z0-9._:-]{8,100}\z/D', $data['idempotency_key']) || ! is_string($data['mac_address'] ?? null) || ! preg_match('/\A[A-Za-z0-9_+\/=\-]{6,2048}\z/D', $data['mac_address'])) {
            throw new ApiError('أدخل معرّف الجهاز الكامل ومرجع طلب صحيحاً', 422);
        }
        if (isset($data['active_code']) && ! is_string($data['active_code'])) {
            throw new ApiError('رمز التفعيل غير صحيح', 422);
        }
        $code = trim($data['active_code'] ?? '');
        $orderId = $data['order_id'] ?? null;
        if ($orderId !== null) {
            if (! is_string($orderId)) {
                throw new ApiError('الطلب غير صحيح', 422);
            }
            $order = collect(app(Store::class)->read()['orders'])->firstWhere('id', $orderId);
            $execution = DB::table('digital_executions')->where('order_id', $orderId)->first();
            if (! $order || ($customerId !== null && ($order['customerId'] ?? null) !== $customerId) || ! $execution || $execution->provider !== 'rabiaa' || $execution->state !== 'delivered') {
                throw new ApiError('البطاقة غير متاحة لهذا الحساب', 404);
            }
            $receipt = app(DigitalSales::class)->receipt($orderId);
            $saved = $receipt['codes'][0]['code'] ?? '';
            if ($saved === '' || ($code !== '' && ! hash_equals($saved, $code))) {
                throw new ApiError('رمز البطاقة لا يطابق العملية', 422);
            }
            $code = $saved;
        }
        if (! preg_match('/\A[A-Za-z0-9_ .\-]{1,2000}\z/D', $code)) {
            throw new ApiError('أدخل PIN بطاقة التفعيل', 422);
        }
        $payload = ['macAddress' => $data['mac_address'], 'activeCode' => $code];
        $hash = hash('sha256', Store::json([$payload, $orderId]));
        $settings = app(DigitalProviders::class)->settings('rabiaa');
        [$row, $send] = DB::transaction(function () use ($actor, $data, $payload, $hash, $orderId, $settings) {
            app(Store::class)->read(true);
            $current = app(DigitalProviders::class)->settings('rabiaa');
            if ($current['version'] !== $settings['version'] || ! hash_equals($current['key'], $settings['key'])) {
                throw new ApiError('تغير ربط المزوّد؛ أعد فتح الصفحة', 409);
            }
            $old = DB::table('rabiaa_activations')->where('actor', $actor)->where('request_key', $data['idempotency_key'])->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw new ApiError('مرجع الطلب مستخدم لبيانات مختلفة', 409);
                }

                return [$old, false];
            }
            $pinHash = hash_hmac('sha256', $payload['activeCode'], (string) config('app.key'));
            if (DB::table('rabiaa_activations')->where('active_pin_hash', $pinHash)->exists()) {
                throw new ApiError('لهذا الكود تفعيل سابق ناجح أو غير محسوم؛ راجع السجل دون إعادة الإرسال', 409);
            }
            $id = (string) Str::uuid();
            DB::table('rabiaa_activations')->insert(['id' => $id, 'actor' => $actor, 'request_key' => $data['idempotency_key'], 'request_hash' => $hash, 'active_pin_hash' => $pinHash, 'order_id' => $orderId, 'connection_version' => $settings['version'], 'encrypted_payload' => Crypt::encryptString(Store::json($payload)), 'status' => 'pending', 'message' => 'أُرسل طلب التفعيل؛ بانتظار نتيجة الشركة.', 'created' => time()]);

            return [DB::table('rabiaa_activations')->where('id', $id)->first(), true];
        });
        if (! $send) {
            return $this->dto($row);
        }
        $result = ['status' => 'review', 'message' => 'لم يصل تأكيد التفعيل. احتُفظ بالطلب للمراجعة دون خصم أو إعادة إرسال.'];
        $status = null;
        $body = null;
        try {
            $response = Http::acceptJson()->withToken($settings['key'])->connectTimeout(8)->timeout(25)->withoutRedirecting()->post('https://active.nojoomalrabiaa.com/v1/active', $payload);
            $status = $response->status();
            if (strlen($response->body()) <= 65536) {
                try {
                    $value = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR);
                    if (is_array($value) && ! array_is_list($value)) {
                        $body = $value;
                    }
                } catch (\JsonException) {
                }
            }
            $result = app(ActivationResult::class)->classify($status, $body);
        } catch (\Throwable) {
            // Do not retry: the provider may have activated the subscription.
        }
        DB::table('rabiaa_activations')->where('id', $row->id)->update(['status' => $result['status'], 'message' => $result['message'], 'http_status' => $status, 'finished' => time(), 'encrypted_response' => $body !== null ? Crypt::encryptString(Store::json($body)) : null, ...($result['status'] === 'failed' ? ['active_pin_hash' => null] : [])]);

        return $this->dto(DB::table('rabiaa_activations')->where('id', $row->id)->first());
    }
}
