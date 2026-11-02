<?php

namespace App\Services;

/** Internal trusted payment evidence. Manual receipts additionally require a step-up approved DB record; never hydrate HTTP JSON. */
final readonly class VerifiedPayment
{
    public function __construct(
        public string $orderId,
        public string $provider,
        public string $transactionId,
        public string $eventId,
        public string $amount,
        public string $currency,
    ) {}
}
