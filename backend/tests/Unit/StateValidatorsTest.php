<?php

namespace Tests\Unit;

use App\Services\Accounts;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StateValidatorsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    public function test_unchanged_public_snapshot_has_empty_304_and_changed_state_returns_a_body(): void
    {
        $first = $this->getJson('/api/state')->assertOk()->assertHeader('ETag')->assertHeader('Vary', 'Cookie');
        $tag = $first->headers->get('ETag');
        $this->withHeader('If-None-Match', $tag)->getJson('/api/state')->assertStatus(304)->assertContent('')->assertHeader('ETag', $tag);
        $store = app(Store::class);
        $state = $store->read();
        $state['settings']['name'] = 'Changed shop';
        $store->save($state);
        $changed = $this->getJson('/api/state')->assertOk()->assertJsonPath('settings.name', 'Changed shop');
        $this->assertNotSame($tag, $changed->headers->get('ETag'));
    }

    public function test_same_revision_different_customer_never_reuses_other_identity(): void
    {
        $store = app(Store::class);
        $state = $store->read();
        $first = Accounts::newCustomer(['name' => 'First', 'email' => ''], '07712345678');
        $second = Accounts::newCustomer(['name' => 'Second', 'email' => ''], '07712345679');
        $state['customers'] = [$first, $second];
        $store->save($state);
        $accounts = app(Accounts::class);
        $this->withCredentials()->withUnencryptedCookie('masal_customer', $accounts->issue('customer', $first['id'])->getValue());
        $response = $this->getJson('/api/state')->assertOk()->assertJsonPath('viewer.id', $first['id']);
        $this->withUnencryptedCookie('masal_customer', $accounts->issue('customer', $second['id'])->getValue())
            ->withHeader('If-None-Match', $response->headers->get('ETag'))
            ->getJson('/api/state')->assertOk()->assertJsonPath('viewer.id', $second['id']);
    }

    public function test_admin_session_is_checked_even_when_client_sends_a_valid_validator(): void
    {
        $token = app(Accounts::class)->issue('admin')->getValue();
        $this->withCredentials()->withUnencryptedCookie('masal_admin', $token);
        $response = $this->getJson('/api/admin/state')->assertOk()->assertHeader('ETag');
        DB::table('auth_tokens')->where('role', 'admin')->delete();
        $this->withHeader('If-None-Match', $response->headers->get('ETag'))->getJson('/api/admin/state')->assertStatus(401);
    }

    public function test_notification_changes_without_store_revision_invalidate_snapshot(): void
    {
        $store = app(Store::class);
        $state = $store->read();
        $customer = Accounts::newCustomer(['name' => 'Reader', 'email' => ''], '07712345678');
        $state['customers'] = [$customer];
        $store->save($state);
        $this->withCredentials()->withUnencryptedCookie('masal_customer', app(Accounts::class)->issue('customer', $customer['id'])->getValue());
        $first = $this->getJson('/api/state')->assertOk();
        app(\App\Services\CustomerNotifications::class)->send($customer['id'], 'New notice', 'Changed independently');
        $this->assertSame($first->json('revision'), $store->read()['revision']);
        $this->withHeader('If-None-Match', $first->headers->get('ETag'))->getJson('/api/state')->assertOk()->assertJsonCount(1, 'notifications');
    }
}
