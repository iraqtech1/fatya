<?php

return [
    'topup' => ['base' => env('TOPUP_BASE_URL', 'https://masal-co.com'), 'key' => env('TOPUP_API_KEY'), 'enabled' => env('TOPUP_ENABLED', false)],
    'rabiaa' => ['base' => env('RABIAA_BASE_URL', 'https://tryapi.nojoomalrabiaa.com/api/v2'), 'key' => env('RABIAA_API_KEY'), 'enabled' => env('RABIAA_ENABLED', false)],
];
