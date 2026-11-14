<?php

namespace App\Services\Captain;

use Illuminate\Validation\ValidationException;

class DigitalMoney
{
    public static function minor(mixed $value, string $field = 'amount', bool $zero = false): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value)) {
            throw ValidationException::withMessages([$field => 'مبالغ الربط يجب أن ترجع كنصوص عشرية دقيقة.']);
        }
        $amount = Money::minor($value, $field, $zero);
        if (! $zero && $amount > 10000000000) {
            throw ValidationException::withMessages([$field => 'مبلغ الخدمة يتجاوز الحد المسموح.']);
        }

        return $amount;
    }

    public static function phone(string $value): string
    {
        $value = strtr($value, array_combine(preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY), str_split('01234567890123456789')));
        $value = preg_replace('/[\s()\-]/u', '', $value);
        $value = preg_replace('/^\+|^00/', '', $value);
        $value = preg_replace('/^0(?=7)/', '964', $value);
        if (! preg_match('/\A9647[0-9]{9}\z/', $value)) {
            throw ValidationException::withMessages(['mobile' => 'أدخل رقم عراقي صحيحًا مثل 07701234567.']);
        }

        return $value;
    }
}
