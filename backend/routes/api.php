<?php

use App\Http\Controllers\StoreController;
use App\Http\Middleware\ApiSecurity;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST', 'PUT'], '/{path}', StoreController::class)->where('path', '.*')->middleware(ApiSecurity::class);
