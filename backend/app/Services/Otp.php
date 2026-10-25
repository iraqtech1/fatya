<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class Otp
{
    public function issue(array $s, array $b, bool $resend): array
    {
        $previous = $resend ? DB::table('otp_challenges')->where('id', CatalogEditor::text($b['challengeId'] ?? null, 100))->lockForUpdate()->first() : null;
        $id = $resend ? ($previous->id ?? '') : (string) Str::uuid();
        $prior = $previous ? json_decode($previous->payload, true, 512, JSON_THROW_ON_ERROR) : null;
        $now = (int) floor(microtime(true) * 1000);
        if ($resend && (! $prior || $now < $prior['resendAt'] || $now >= $prior['expiresAt'])) {
            throw new ApiError('انتظر قبل طلب رمز جديد');
        }
        if ($resend) {
            $account = $prior['account'];
            $mode = $prior['mode'];
        } else {
            $mode = $b['mode'] ?? null;
            $phone = Accounts::phone($b['phone'] ?? null);
            if (! preg_match('/^07[3-9][0-9]{8}$/D', $phone)) {
                throw new ApiError('اكتب رقم هاتف عراقي صحيح');
            }
            $existing = collect($s['customers'])->firstWhere('phone', $phone);
            if (in_array($mode, ['login', 'reset'])) {
                if (! ($existing['active'] ?? false)) {
                    throw new ApiError('الحساب غير موجود أو موقوف');
                }
                $account = $existing;
            } elseif ($mode === 'register') {
                if ($existing) {
                    throw new ApiError('هذا الرقم مسجّل بالفعل');
                }
                $p = CatalogEditor::profile($b);
                if ($p['email'] && collect($s['customers'])->contains('email', $p['email'])) {
                    throw new ApiError('البريد مستخدم');
                }
                $account = Accounts::newCustomer($p, $phone);
            } else {
                throw new ApiError('طريقة التحقق غير صحيحة');
            }
        }
        $code = (string) random_int(100000, 999999);
        $challenge = ['account' => $account, 'mode' => $mode, 'code' => hash('sha256', $code), 'expiresAt' => $now + 300000, 'resendAt' => $now + 60000, 'attempts' => 0];
        $development = config('fatya.development_otp') && ! app()->isProduction();
        if (! $development) {
            $url = config('fatya.otp_url');
            $token = config('fatya.otp_token');
            if (! $url || ! $token || ! str_starts_with($url, 'https://')) {
                throw new ApiError('خدمة إرسال رموز التحقق غير مهيأة', 503);
            }
            try {
                $result = Http::timeout(10)->withToken($token)->post($url, ['phone' => $account['phone'], 'code' => $code]);
            } catch (ConnectionException) {
                throw new ApiError('تعذّر إرسال الرمز', 502);
            }
            if (! $result->successful()) {
                throw new ApiError('تعذّر إرسال الرمز', 502);
            }
        }
        DB::table('otp_challenges')->where('expires', '<=', $now)->delete();
        DB::table('otp_challenges')->updateOrInsert(['id' => $id], ['payload' => Store::json($challenge), 'expires' => $challenge['expiresAt']]);

        return ['challengeId' => $id, 'mode' => $mode, 'expiresAt' => $challenge['expiresAt'], 'resendAt' => $challenge['resendAt'], ...($development ? ['previewCode' => $code] : [])];
    }

    public function verify(array $b, bool $reset): array
    {
        $id = CatalogEditor::text($b['challengeId'] ?? '', 100);
        $row = DB::table('otp_challenges')->where('id', $id)->lockForUpdate()->first();
        $c = $row ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) : null;
        if (! $c || (($c['mode'] === 'reset') !== $reset) || microtime(true) * 1000 >= $c['expiresAt'] || $c['attempts'] >= 5) {
            throw new ApiError('اطلب رمز تحقق جديداً');
        }
        $c['attempts']++;
        DB::table('otp_challenges')->where('id', $id)->update(['payload' => Store::json($c)]);
        if (! is_string($b['code'] ?? null) || ! hash_equals($c['code'], hash('sha256', $b['code']))) {
            throw new ApiError('رمز التحقق غير صحيح');
        }

        return $c;
    }
}
