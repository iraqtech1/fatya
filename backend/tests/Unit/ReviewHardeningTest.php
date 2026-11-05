<?php

namespace Tests\Unit;

use App\Services\Accounts;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ReviewHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    public function test_public_reads_never_decode_unrelated_or_admin_only_payloads(): void
    {
        foreach (['imports', 'suppliers', 'events'] as $table) {
            DB::table($table)->insert(['id' => 'private', 'payload' => 'not-json']);
        }
        DB::table('customers')->insert(['id' => 'other', 'phone' => '07712345679', 'payload' => 'not-json']);
        foreach (['orders', 'tickets'] as $table) {
            DB::table($table)->insert(['id' => 'other', 'customer_id' => 'other', 'payload' => 'not-json', ...($table === 'orders' ? ['reference' => 'other'] : [])]);
        }
        $guest = app(Store::class)->readPublic(null);
        foreach (['imports', 'suppliers', 'events', 'customers', 'orders', 'tickets'] as $key) {
            $this->assertSame([], $guest[$key]);
        }
        DB::table('customers')->insert(['id' => 'owner', 'phone' => '07712345678', 'payload' => Store::json(['id' => 'owner', 'active' => true])]);
        foreach (['orders', 'tickets'] as $table) {
            DB::table($table)->insert(['id' => 'owned', 'customer_id' => 'owner', 'payload' => Store::json(['id' => 'owned', 'customerId' => 'owner']), ...($table === 'orders' ? ['reference' => 'owned'] : [])]);
        }
        $state = app(Store::class)->readPublic('owner');
        $this->assertSame('owner', $state['customers'][0]['id']);
        $this->assertSame(['owned'], array_column($state['orders'], 'id'));
        $this->assertSame(['owned'], array_column($state['tickets'], 'id'));
        $this->assertSame([], $state['imports']);
    }

    public function test_security_headers_are_set_without_claiming_hsts_on_http(): void
    {
        $this->get('http://localhost/api/health')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'")
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/api/health')->assertOk()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_ticket_quota_is_per_account_and_rejected_requests_create_no_records(): void
    {
        $store = app(Store::class);
        $state = $store->read();
        $first = Accounts::newCustomer(['name' => 'First', 'email' => ''], '07712345678');
        $second = Accounts::newCustomer(['name' => 'Second', 'email' => ''], '07712345679');
        $state['customers'] = [$first, $second];
        $store->save($state);
        foreach ([$first, $second] as $customer) {
            RateLimiter::clear('fatya:tickets:'.$customer['id']);
        }
        $this->withCredentials()->withHeaders(['X-Fatya-Request' => '1'])
            ->withUnencryptedCookie('masal_customer', app(Accounts::class)->issue('customer', $first['id'])->getValue());
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/tickets', ['subject' => 'Help', 'message' => 'Question '.$i])->assertCreated();
        }
        $this->postJson('/api/tickets', ['subject' => 'Excess', 'message' => 'Rejected'])->assertStatus(429)->assertHeader('Retry-After');
        $this->assertDatabaseCount('tickets', 5);
        $this->withUnencryptedCookie('masal_customer', app(Accounts::class)->issue('customer', $second['id'])->getValue());
        $this->postJson('/api/tickets', ['subject' => 'Other customer', 'message' => 'Allowed'])->assertCreated();
        $this->assertDatabaseCount('tickets', 6);
    }
}
