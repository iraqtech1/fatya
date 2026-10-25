<?php

namespace App\Services;

/** Internal input from a future authenticated gateway adapter; never hydrate from HTTP JSON. */
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
