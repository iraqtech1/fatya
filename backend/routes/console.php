<?php

use App\Services\DigitalSales;
use App\Services\NotificationCampaigns;
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

Artisan::command('fatya:digital-deliver', function (DigitalSales $sales) {
    $this->info(json_encode($sales->run(), JSON_THROW_ON_ERROR));
})->purpose('Execute paid digital orders once; uncertain outcomes require review');
Schedule::command('fatya:digital-deliver')->everyMinute()->withoutOverlapping();

Artisan::command('fatya:notification-campaigns', function (NotificationCampaigns $campaigns) {
    $this->info(json_encode($campaigns->run(), JSON_THROW_ON_ERROR));
})->purpose('Create messages for explicitly reviewed and scheduled campaigns');
Schedule::command('fatya:notification-campaigns')->everyMinute()->withoutOverlapping();

Artisan::command('fatya:expiry-alerts', function (NotificationCampaigns $campaigns) {
    try {
        $this->info(json_encode($campaigns->expiry(), JSON_THROW_ON_ERROR));
    } catch (Throwable $error) {
        DB::table('notification_rules')->where('id', 1)->update(['last_run' => time(), 'last_result' => 'failed']);
        throw $error;
    }
})->purpose('Create per-card expiry alerts only when explicitly enabled');
Schedule::command('fatya:expiry-alerts')->hourly()->withoutOverlapping();
