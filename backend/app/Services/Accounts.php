<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class Accounts
{
    public function session(Request $request, string $role): object
    {
        $token = $request->cookie('masal_'.$role);
        $row = is_string($token) ? DB::table('auth_tokens')->where('token', hash('sha256', $token))->where('expires', '>', time())->where('role', $role)->first() : null;
        if (! $row) {
            throw new ApiError('سجّل الدخول أولاً', 401);
        }

        return $row;
    }

    public function customer(Request $request, array $state): array
    {
        $session = $this->session($request, 'customer');
        $customer = collect($state['customers'])->firstWhere('id', $session->customer_id);
        if (! ($customer['active'] ?? false)) {
            throw new ApiError('الحساب موقوف', 403);
        }

        return $customer;
    }

    public function issue(string $role, ?string $customer = null): Cookie
    {
        $token = bin2hex(random_bytes(48));
        $age = $role === 'admin' ? 28800 : 2592000;
        DB::table('auth_tokens')->where('expires', '<=', time())->delete();
        DB::table('auth_tokens')->insert(['token' => hash('sha256', $token), 'role' => $role, 'customer_id' => $customer, 'expires' => time() + $age]);

        return cookie('masal_'.$role, $token, $age / 60, '/', null, config('fatya.secure_cookies'), true, false, 'strict');
    }

    public function setPassword(string $id, mixed $password): void
    {
        self::validatePassword($password);
        DB::table('customer_credentials')->updateOrInsert(['customer_id' => $id], ['password' => Hash::make($password)]);
        DB::table('auth_tokens')->where('customer_id', $id)->where('role', 'customer')->delete();
    }

    public function checkPassword(string $password, string $stored): bool
    {
        if (! str_starts_with($stored, 'node-scrypt:')) {
            return Hash::check($password, $stored);
        }
        if (! function_exists('sodium_crypto_pwhash_scryptsalsa208sha256')) {
            throw new ApiError('تحتاج الحسابات المنقولة إضافة Sodium على الخادم', 503);
        }
        $parts = explode(':', $stored);
        if (count($parts) !== 3 || ! preg_match('/^[a-f0-9]{32}$/D', $parts[1]) || ! preg_match('/^[a-f0-9]{128}$/D', $parts[2])) {
            return false;
        }
        // Node scrypt defaults: N=16384, r=8, p=1; old salt is 32 ASCII hex bytes.
        $actual = sodium_crypto_pwhash_scryptsalsa208sha256(64, $password, $parts[1], 524288, 16777216);

        return hash_equals($parts[2], bin2hex($actual));
    }

    public static function validatePassword(mixed $password): void
    {
        if (! is_string($password) || mb_strlen($password) < 8 || mb_strlen($password) > 128) {
            throw new ApiError('كلمة المرور يجب أن تكون من 8 أرقام أو حروف فما فوق');
        }
    }

    public static function phone(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = strtr($value, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $value = preg_replace('/[\s()+-]/u', '', $value);
        if (str_starts_with($value, '00964')) {
            $value = '0'.substr($value, 5);
        } elseif (str_starts_with($value, '964')) {
            $value = '0'.substr($value, 3);
        }

        return $value;
    }

    public static function identity(array $c): array
    {
        return array_intersect_key($c, array_flip(['id', 'name', 'phone', 'email', 'previewId']));
    }

    public static function newCustomer(array $profile, string $phone): array
    {
        return [...$profile, 'id' => (string) Str::uuid(), 'phone' => $phone, 'active' => true, 'kind' => 'فرد', 'date' => now('Asia/Baghdad')->toDateString()];
    }
}
