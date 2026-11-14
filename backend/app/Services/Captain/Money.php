<?php

namespace App\Services\Captain;

use Illuminate\Validation\ValidationException;

class Money
{
    public const MAX_MINOR = 999999999999999;

    public static function minor(mixed $value, string $field = 'amount', bool $allowZero = false): int
    {
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?\z/D', $value)) {
            throw ValidationException::withMessages([$field => 'أدخل مبلغاً نصياً صحيحاً بمنزلتين عشريتين كحد أقصى.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($minor > self::MAX_MINOR || $minor < ($allowZero ? 0 : 1)) {
            throw ValidationException::withMessages([$field => 'المبلغ يجب أن يكون موجباً وضمن الحد المسموح.']);
        }

        return $minor;
    }

    public static function decimal(int $minor): string
    {
        $value = abs($minor);

        return ($minor < 0 ? '-' : '').intdiv($value, 100).'.'.str_pad((string) ($value % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function multiply(int $minor, int $quantity): int
    {
        if ($minor < 0 || $quantity < 1 || $minor > intdiv(self::MAX_MINOR, $quantity)) {
            throw ValidationException::withMessages(['amount' => 'المبلغ الإجمالي يتجاوز الحد المسموح.']);
        }

        return $minor * $quantity;
    }
}
