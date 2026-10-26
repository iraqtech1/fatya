<?php

use Illuminate\Support\Facades\Route;

$frontend = function () {
    $file = public_path('index.html');
    abort_unless(is_file($file), 503, 'Build the Vue frontend with npm run build:host.');

    return response()->file($file, ['Cache-Control' => 'no-cache']);
};

Route::get('/', $frontend);
Route::get('/app', $frontend);
Route::get('/admin/{section?}', $frontend)->where('section', '[A-Za-z0-9_-]+');
Route::get('/ar/{path?}', $frontend)->where('path', '[A-Za-z0-9_/-]+');
