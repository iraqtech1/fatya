<?php

namespace App\Services;

/** Public sale description frozen at reservation; provider costs and secrets stay server-side. */
class SaleSnapshots
{
    public static function capture(array $product, int $denom, int $quantity, string $amount, ?array $service = null): array
    {
        $translations = [];
        foreach (['ar', 'ku', 'en'] as $locale) {
            $row = $product['translations'][$locale] ?? [];
            $translations[$locale] = [
                'name' => (string) ($row['name'] ?? ''),
                'denominationName' => (string) (($service['display']['translations'][$locale]['title'] ?? '') ?: ($service['display']['translations']['ar']['title'] ?? '') ?: ($row['denomNames'][$denom] ?? '')),
                'denominationDescription' => (string) (($service['display']['translations'][$locale]['description'] ?? '') ?: ($service['display']['translations']['ar']['description'] ?? '') ?: ($row['denomDescriptions'][$denom] ?? '')),
            ];
        }

        return [
            'version' => 1,
            'productId' => $product['id'],
            'companyId' => $product['manufacturerId'] ?? null,
            'name' => $product['name'],
            'unit' => $product['unit'],
            'denominationIndex' => $denom,
            'denominationValue' => $product['values'][$denom] ?? null,
            'denominationName' => (string) ($product['denomNames'][$denom] ?? ''),
            'denominationDescription' => (string) ($product['denomDescriptions'][$denom] ?? ''),
            'translations' => $translations,
            'source' => $service ? 'api' : 'stock',
            'serviceTitle' => (string) (($service['display']['translations']['ar']['title'] ?? '') ?: ($service['title'] ?? '')),
            'providerId' => $service['providerId'] ?? null,
            'serviceType' => $service['type'] ?? null,
            'quantity' => $quantity,
            'total' => OrderFulfillment::amount($amount),
            'currency' => 'IQD',
        ];
    }
}
