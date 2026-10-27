<?php

namespace Tests\Unit;

use App\Services\Accounts;
use App\Services\CustomerNotifications;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function fixture(): array
    {
        $s = app(Store::class)->read();
        $customer = Accounts::newCustomer(['name' => 'Disposable', 'email' => ''], '07712345678');
        $other = Accounts::newCustomer(['name' => 'Preserved', 'email' => ''], '07712345679');
        $s['customers'] = [$customer, $other];
        app(Store::class)->save($s);
        DB::table('customer_credentials')->insert(['customer_id' => $customer['id'], 'password' => 'fixture-only']);
        DB::table('otp_challenges')->insert(['id' => (string) Str::uuid(), 'payload' => Store::json(['account' => $customer]), 'expires' => time() * 1000 + 300000]);
        app(CustomerNotifications::class)->send($customer['id'], 'Fixture', 'Fixture');
        $session = app(Accounts::class)->issue('customer', $customer['id'])->getValue();

        return [$s, $customer, $other, $session];
    }

    private function admin(): void
    {
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);
    }

    public function test_delete_requires_admin_confirmation_and_current_revision_then_revokes_access(): void
    {
        [$s, $customer, $other, $session] = $this->fixture();
        $body = ['id' => $customer['id'], 'revision' => $s['revision'], 'confirm' => true];
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/admin/customers/delete', $body)->assertStatus(401);
        $this->admin();
        $this->postJson('/api/admin/customers/delete', [...$body, 'confirm' => false])->assertStatus(400);
        $this->postJson('/api/admin/customers/delete', [...$body, 'revision' => 0])->assertStatus(409);
        $this->assertDatabaseCount('customers', 2);
        $this->postJson('/api/admin/customers/delete', $body)->assertOk()->assertJsonCount(1, 'customers');
        $this->assertDatabaseMissing('customers', ['id' => $customer['id']]);
        $this->assertDatabaseHas('customers', ['id' => $other['id']]);
        $this->assertDatabaseMissing('customer_credentials', ['customer_id' => $customer['id']]);
        $this->assertDatabaseMissing('auth_tokens', ['customer_id' => $customer['id']]);
        $this->assertDatabaseCount('customer_notifications', 0);
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->withUnencryptedCookie('masal_customer', $session)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_customers_with_orders_or_support_tickets_are_preserved(): void
    {
        [$s, $customer] = $this->fixture();
        $this->admin();
        foreach (['orders', 'tickets'] as $table) {
            $row = ['id' => 'linked-'.$table, 'customer_id' => $customer['id'], 'payload' => '{}'];
            if ($table === 'orders') {
                $row['reference'] = 'fixture';
            }
            DB::table($table)->insert($row);
            $this->postJson('/api/admin/customers/delete', ['id' => $customer['id'], 'revision' => $s['revision'], 'confirm' => true])->assertStatus(409);
            $this->assertDatabaseHas('customers', ['id' => $customer['id']]);
            $this->assertDatabaseHas($table, ['id' => $row['id']]);
            $this->assertDatabaseCount('customer_credentials', 1);
            DB::table($table)->where('id', $row['id'])->delete();
        }
    }
}
