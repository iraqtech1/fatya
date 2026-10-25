<?php

return [
    'reservation_minutes' => env('RESERVATION_MINUTES', 15),
    'allow_payment_simulation' => env('ALLOW_PAYMENT_SIMULATION', false),
    'admin_user' => env('ADMIN_USER', 'masal'),
    'admin_password' => env('ADMIN_PASSWORD'),
    'development_otp' => env('DEV_OTP', false),
    'otp_url' => env('OTP_WEBHOOK_URL'),
    'otp_token' => env('OTP_WEBHOOK_TOKEN'),
    'secure_cookies' => env('SESSION_SECURE_COOKIE', env('APP_ENV') === 'production'),
];
