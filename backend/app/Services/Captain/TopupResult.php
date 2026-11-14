<?php

namespace App\Services\Captain;

/** V2.1 rules copied from MasalTopupAdapter::purchaseResult; no transport or retry. */
class TopupResult
{
    public static function classify(array $result, string $mobile): array
    {
        if (! in_array(strtolower((string) ($result['status'] ?? '')), ['success', 'succeed'], true)) {
            return ['status' => in_array((string) ($result['code'] ?? ''), ['101', '103'], true) && in_array((string) ($result['status'] ?? ''), ['400', '401'], true) ? 'failed' : 'review'];
        }
        $details = $result['data'] ?? $result;
        if (! is_array($details) || isset($result['data']) && ! in_array(strtolower((string) ($details['status'] ?? '')), ['success', 'succeed'], true)) {
            return ['status' => 'review'];
        }
        if (isset($details['logicalResource']['value'])) {
            try {
                if (DigitalMoney::phone((string) $details['logicalResource']['value']) !== $mobile) {
                    return ['status' => 'review'];
                }
            } catch (\Throwable) {
                return ['status' => 'review'];
            }
        }
        $reference = $details['id'] ?? null;
        if (is_int($reference) && $reference > 0) {
            $reference = (string) $reference;
        }
        if (! is_string($reference) || $reference === '' || strlen($reference) > 190) {
            return ['status' => 'review'];
        }

        return ['status' => 'succeeded', 'reference' => $reference];
    }
}
