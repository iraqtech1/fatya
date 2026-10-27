<?php

use App\Services\OrderFulfillment;
use App\Services\PushDelivery;
use App\Services\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('fatya:seed', function (Store $store) {
    $store->seed();
    $this->info('Fatya catalog ready. Existing stores are preserved.');
})->purpose('Initialize the existing Vue catalog without overwriting store data');

Artisan::command('fatya:prune', function () {
    DB::table('auth_tokens')->where('expires', '<=', time())->delete();
    DB::table('otp_challenges')->where('expires', '<=', (int) (microtime(true) * 1000))->delete();
    $this->info('Expired sessions and challenges pruned.');
})->purpose('Remove expired authentication data');

Schedule::command('fatya:prune')->hourly()->withoutOverlapping();
Artisan::command('fatya:expire-reservations', function (OrderFulfillment $fulfillment) {
    $this->info('Expired reservations released: '.$fulfillment->expire());
})->purpose('Release unpaid expired inventory reservations');
Schedule::command('fatya:expire-reservations')->everyMinute()->withoutOverlapping();

Artisan::command('fatya:push-deliver', function (PushDelivery $delivery) {
    $this->info(json_encode($delivery->run(), JSON_THROW_ON_ERROR));
})->purpose('Deliver committed customer notifications through Takeed');
Schedule::command('fatya:push-deliver')->everyMinute()->withoutOverlapping();
