<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class DigitalConnections
{
    public function save(array $b): array
    {
        $provider = $b['provider'] ?? '';
        if (! in_array($provider, ['topup', 'rabiaa'], true) || ! is_bool($b['active'] ?? null)) {
            throw new ApiError('بيانات الربط غير صحيحة', 422);
        }
        $city = CatalogEditor::text($b['ownerCity'] ?? '', 100);
        $key = $b['credential'] ?? '';
        if (! is_string($key) || strlen($key) > 4096 || preg_match('/[\x00-\x20\x7f]/', $key) || preg_match('/^Bearer/i', $key)) {
            throw new ApiError('أدخل المفتاح الخام دون Bearer أو فراغات', 422);
        }

        return DB::transaction(function () use ($b, $provider, $key, $city) {
            $store = app(Store::class);
            $s = $store->read(true);
            $old = DB::table('digital_connections')->where('provider', $provider)->first();
            if (($b['version'] ?? null) !== (int) ($old?->version ?? 0)) {
                throw new ApiError('إعداد الربط تغير؛ حدّث الصفحة', 409);
            }
            $current = $old?->credential ? Crypt::decryptString($old->credential) : (config('digital.'.$provider.'.key') ?? '');
            $changed = $key !== '' && ! hash_equals($current, $key);
            if ($changed) {
                $reason = CatalogEditor::text($b['reason'] ?? '', 500);
                if ($current && ! $reason) {
                    throw new ApiError('اكتب سبب تغيير المفتاح', 422);
                }
                if (DB::table('rabiaa_activations')->whereIn('status', ['pending', 'review'])->exists() && $provider === 'rabiaa') {
                    throw new ApiError('توجد طلبات تفعيل غير محسومة؛ راجعها قبل تغيير المفتاح', 409);
                }
                if (DB::table('digital_executions')->where('provider', $provider)->whereNotIn('state', ['delivered', 'failed', 'cancelled', 'expired', 'refunded'])->exists()) {
                    throw new ApiError('توجد عمليات معلقة أو للمراجعة؛ لا يمكن تبديل المفتاح', 409);
                }
            }
            if ($b['active'] && ! ($key ?: $current)) {
                throw new ApiError('أدخل مفتاح الشركة أولاً', 422);
            }
            $ownerCity = $city !== '' ? $city : ($old?->owner_city ?? config('digital.rabiaa.owner_city', ''));
            if ($provider === 'rabiaa' && $b['active'] && $ownerCity === '') {
                throw new ApiError('حدد محافظة صاحب توكن الرابعة', 422);
            }
            $values = ['owner_city' => $provider === 'rabiaa' ? $ownerCity : null, 'active' => $b['active'], 'version' => (int) ($old?->version ?? 0) + 1, 'credential' => $key !== '' ? Crypt::encryptString($key) : ($old?->credential ?? null)];
            if ($changed || $provider === 'rabiaa' && $ownerCity !== ($old?->owner_city ?? '')) {
                $values += ['balance' => null, 'balance_updated' => null, 'premium_provinces' => null];
                DB::table('digital_catalog')->where(fn ($q) => $q->where('connection', $provider)->orWhere('connection', 'like', $provider.':%'))->delete();
                DB::table('digital_offers')->where('connection', $provider)->update(['active' => false]);
            }
            DB::table('digital_connections')->updateOrInsert(['provider' => $provider], $values);
            $store->event($s, 'تحديث ربط الشركة: '.$provider.($changed ? ' / تغيير مفتاح' : ''));
            $store->save($s);

            return ['connections' => app(DigitalProviders::class)->status(), 'revision' => $s['revision']];
        });
    }
}
