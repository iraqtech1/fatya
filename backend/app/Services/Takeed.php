<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class Takeed
{
    public function enabled(): bool
    {
        return (bool) config('fatya.takeed_key') && (bool) config('fatya.takeed_app_id');
    }

    public function client(): PendingRequest
    {
        if (! $this->enabled()) {
            throw new ApiError('خدمة الإرسال غير مهيأة', 503);
        }

        return Http::baseUrl('https://control.takeed-iq.com/api/v1')->acceptJson()->withHeaders(['Authorization' => 'Key '.config('fatya.takeed_key')])->connectTimeout(4)->timeout(12)->withoutRedirecting();
    }

    // An opaque per-account alias prevents guessing another customer's push identity.
    public function alias(string $customer): string
    {
        return hash_hmac('sha256', 'fatya-push:'.$customer, config('app.key'));
    }

    public function clientConfig(string $customer): array
    {
        return ['enabled' => $this->enabled(), 'appId' => config('fatya.takeed_app_id'), 'externalId' => $this->alias($customer), 'identityHash' => config('fatya.takeed_identity_secret') ? hash_hmac('sha256', $this->alias($customer), config('fatya.takeed_identity_secret')) : null];
    }

    public function balance(bool $refresh = false): array
    {
        $key = 'fatya:otp-balance:'.hash('sha256', (string) config('fatya.takeed_app_id'));
        if ($refresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 30, function () {
            try {
                $response = $this->client()->get('/otp/balance');
            } catch (ConnectionException) {
                throw new ApiError('تعذّر جلب رصيد رسائل التحقق', 502);
            }
            $available = $response->json('available_messages');
            if (! $response->successful() || ! is_int($available) || $available < 0) {
                throw new ApiError('تعذّر جلب رصيد رسائل التحقق', 502);
            }

            return ['availableMessages' => $available, 'target' => config('fatya.takeed_target'), 'checkedAt' => now()->toIso8601String()];
        });
    }
}
