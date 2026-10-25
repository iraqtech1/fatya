<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $file = public_path('index.html');
    abort_unless(is_file($file), 503, 'Build the Vue frontend with npm run build:host.');

    return response()->file($file);
});
