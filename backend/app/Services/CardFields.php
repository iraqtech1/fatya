<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;

class CardFields
{
    public static function extra(array $product, array $row, ?int $denom = null): array
    {
        $policy = ProductFields::policy($product, $denom);
        if ($policy['serial'] && (! is_string($row['serial'] ?? null) || ! trim($row['serial']))) {
            throw new ApiError('السيريال مطلوب لهذه البطاقة', 422);
        }
        $details = [];
        foreach (['cvc' => 'CVC / CVV', 'reference' => 'الرقم المرجعي'] as $key => $label) {
            $value = $row[$key] ?? '';
            if (! is_string($value) || strlen($value) > 256 || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw new ApiError($label.' يجب أن يكون نصاً صحيحاً', 422);
            }
            $value = trim($value);
            if ($policy[$key] && $value === '') {
                throw new ApiError($label.' مطلوب لهذه البطاقة', 422);
            }
            if ($key === 'cvc' && $value !== '' && ! preg_match('/^[0-9]{3,4}$/D', $value)) {
                throw new ApiError('CVC / CVV يجب أن يتكون من 3 أو 4 أرقام', 422);
            }
            if ($value !== '') {
                $details[$key] = $value;
            }
        }

        return $details;
    }

    public static function encrypt(array $product, array $details, ?int $denom = null): ?string
    {
        return $details || ProductFields::policy($product, $denom)['serial'] ? Crypt::encryptString(Store::json($details)) : null;
    }

    public static function details(array|object $card): array
    {
        $value = is_array($card) ? ($card['encrypted_details'] ?? null) : ($card->encrypted_details ?? null);
        if (! $value) {
            return [];
        }
        $details = json_decode(Crypt::decryptString($value), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($details) || array_diff(array_keys($details), ['cvc', 'reference'])) {
            throw new ApiError('بيانات البطاقة تحتاج مراجعة', 409);
        }
        foreach ($details as $value) {
            if (! is_string($value)) {
                throw new ApiError('بيانات البطاقة تحتاج مراجعة', 409);
            }
        }

        return $details;
    }
}
