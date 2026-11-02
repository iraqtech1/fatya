<?php

return [
    'manual_topup_enabled' => env('DIGITAL_MANUAL_TOPUP_ENABLED', true),
    'purchases_enabled' => env('DIGITAL_PURCHASES_ENABLED', false),
    'topup' => ['base' => env('TOPUP_BASE_URL', 'https://masal-co.com'), 'key' => env('TOPUP_API_KEY'), 'enabled' => env('TOPUP_ENABLED', false)],
    'rabiaa' => ['base' => env('RABIAA_BASE_URL', 'https://tryapi.nojoomalrabiaa.com/api/v2'), 'key' => env('RABIAA_API_KEY'), 'enabled' => env('RABIAA_ENABLED', false)],
];
