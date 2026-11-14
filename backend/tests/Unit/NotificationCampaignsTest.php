<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\CustomerNotifications;
use App\Services\NotificationCampaigns;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NotificationCampaignsTest extends TestCase
{
    use RefreshDatabase;

    public function test_cold_start_notification_link_cannot_read_another_customers_message(): void
    {
        $state = $this->fixture();
        $id = app(CustomerNotifications::class)->send($state['customers'][0]['id'], 'Fixture private notice', 'Only its owner can read this');
        $this->withCredentials()->withUnencryptedCookie('masal_customer', app(Accounts::class)->issue('customer', $state['customers'][0]['id'])->getValue());
        $this->getJson('/api/notifications/'.$id)->assertOk()->assertJsonPath('notification.id', $id);
        $this->assertTrue((bool) DB::table('customer_notifications')->where('id', $id)->value('unread'));
        $this->withUnencryptedCookie('masal_customer', app(Accounts::class)->issue('customer', $state['customers'][1]['id'])->getValue());
        $this->getJson('/api/notifications/'.$id)->assertNotFound();
    }

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function fixture(): array
    {
        Http::preventStrayRequests();
        config(['fatya.takeed_key' => null]);
        request()->cookies->set('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        $state = app(Store::class)->read();
        $state['customers'] = [Accounts::newCustomer(['name' => 'Fixture one', 'email' => ''], '07700000000'), Accounts::newCustomer(['name' => 'Fixture two', 'email' => ''], '07800000000')];
        $state['products'] = [['id' => 1, 'name' => 'Fixture cards', 'active' => true, 'unit' => 'IQD', 'values' => [5000], 'prices' => [5250], 'stock' => [0]]];
        app(Store::class)->save($state);

        return $state;
    }

    public function test_campaign_requires_review_and_explicit_send_and_snapshots_the_audience(): void
    {
        $this->fixture();
        $service = app(NotificationCampaigns::class);
        $draft = $service->save(['title' => 'Fixture notice', 'body' => 'Safe message', 'audience' => 'all', 'push' => false, 'targetPath' => '/orders']);
        $this->assertSame(['created' => 0, 'skipped' => 0], $service->run());
        try {
            $service->transition(['id' => $draft['id'], 'version' => 1], true);
            $this->fail('Draft cannot send before review');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $service->transition(['id' => $draft['id'], 'version' => 1], false);
        $this->assertSame(['created' => 0, 'skipped' => 0], $service->run());
        $state = app(Store::class)->read();
        $state['customers'][] = Accounts::newCustomer(['name' => 'Joined after review', 'email' => ''], '07900000000');
        app(Store::class)->save($state);
        $service->transition(['id' => $draft['id'], 'version' => 2], true);
        $this->assertSame(['created' => 2, 'skipped' => 0], $service->run());
        $this->assertSame(['created' => 0, 'skipped' => 0], $service->run());
        $this->assertDatabaseCount('customer_notifications', 2);
        $this->assertSame('sent', DB::table('notification_campaigns')->value('status'));
        $this->assertSame(0, DB::table('customer_notifications')->where('push_status', 'pending')->count());
        Http::assertNothingSent();
    }

    public function test_edit_invalidates_review_and_external_links_are_rejected(): void
    {
        $this->fixture();
        $service = app(NotificationCampaigns::class);
        $body = ['title' => 'Notice', 'body' => 'Body', 'audience' => 'all', 'push' => false];
        $draft = $service->save($body);
        $service->transition(['id' => $draft['id'], 'version' => 1], false);
        $service->save([...$body, 'id' => $draft['id'], 'version' => 2, 'title' => 'Changed']);
        $this->assertSame('draft', DB::table('notification_campaigns')->value('status'));
        try {
            $service->transition(['id' => $draft['id'], 'version' => 2], true);
            $this->fail('Cannot send reviewed content after an edit');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->expectException(ApiError::class);
        $service->save([...$body, 'targetPath' => '//external.invalid']);
    }

    public function test_per_card_expiry_alerts_are_disabled_by_default_and_do_not_repeat_or_merge_dates(): void
    {
        $this->fixture();
        $service = app(NotificationCampaigns::class);
        $this->assertFalse((bool) $service->rules()['expiry_enabled']);
        $this->assertDatabaseCount('notification_rules', 0);
        foreach ([now('Asia/Baghdad')->addDays(2)->toDateString(), now('Asia/Baghdad')->addDays(40)->toDateString()] as $index => $expiry) {
            DB::table('inventory_cards')->insert(['product_id' => '1', 'denomination' => 5000, 'code_hash' => hash('sha256', 'fake-pin-'.$index), 'encrypted_code' => Crypt::encryptString('fake-pin-'.$index), 'expires_on' => $expiry]);
        }
        $this->assertSame(['enabled' => false, 'created' => 0], $service->expiry());
        $service->saveRules(['version' => 0, 'expiry_enabled' => true, 'expiry_days' => 7, 'repeat_days' => null, 'notify_customer' => false, 'push' => false]);
        $this->assertSame(['enabled' => true, 'created' => 1], $service->expiry());
        $this->assertSame(['enabled' => true, 'created' => 0], $service->expiry());
        $this->assertDatabaseCount('inventory_expiry_alerts', 1);
        $this->assertDatabaseCount('customer_notifications', 0);
        $this->assertStringNotContainsString('fake-pin-', json_encode($service->listing()));
        Http::assertNothingSent();
    }
}
