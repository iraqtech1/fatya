<?php

namespace Tests\Unit;

use App\Services\Accounts;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderVisibilityTest extends TestCase
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
        $state = app(Store::class)->read();
        $customer = Accounts::newCustomer(['name' => 'Visibility fixture', 'email' => ''], '07712345678');
        $state['customers'] = [$customer];
        foreach (['first', 'second', 'third'] as $id) {
            $state['orders'][] = ['id' => $id, 'reference' => 'reference-'.$id, 'customerId' => $customer['id'], 'customer' => $customer['name'], 'name' => 'Fixture card', 'date' => '2026-11-08', 'status' => 'مكتمل', 'paymentStatus' => 'confirmed', 'fulfillmentStatus' => 'delivered', 'codesAvailable' => true, 'price' => 5250, 'quantity' => 1, 'value' => 5000];
        }
        app(Store::class)->save($state);
        DB::table('order_fulfillments')->insert(['order_id' => 'first', 'state' => 'delivered', 'payment_status' => 'confirmed', 'provider' => 'Qi', 'amount' => 5250, 'quantity' => 1, 'expires' => time() + 900]);
        DB::table('payment_receipts')->insert(['id' => (string) Str::uuid(), 'order_id' => 'first', 'provider' => 'Qi', 'transaction_id' => 'kept-transaction', 'event_id' => 'kept-event', 'amount' => 5250, 'currency' => 'IQD', 'outcome' => 'delivered', 'received_at' => time()]);

        return [$state, $customer];
    }

    private function admin(): void
    {
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);
    }

    public function test_single_and_bulk_hide_preserve_customer_history_financial_records_and_totals(): void
    {
        [$state, $customer] = $this->fixture();
        $before = DB::table('order_fulfillments')->get()->toJson();
        $this->admin();
        $this->postJson('/api/admin/orders/hide', ['revision' => $state['revision'], 'ids' => ['first'], 'confirm' => true])->assertOk()->assertJsonPath('adminMetrics.sales', 15750)->assertJsonPath('adminMetrics.orders', 3);
        $s = app(Store::class)->read();
        $this->assertNotEmpty($s['orders'][0]['adminHiddenAt']);
        $this->assertSame('مكتمل', $s['orders'][0]['status']);
        $this->assertSame('confirmed', $s['orders'][0]['paymentStatus']);
        $this->assertSame($before, DB::table('order_fulfillments')->get()->toJson());
        $this->assertDatabaseHas('payment_receipts', ['transaction_id' => 'kept-transaction', 'amount' => 5250]);
        $this->postJson('/api/admin/orders/hide', ['revision' => $s['revision'], 'ids' => ['second', 'third', 'second'], 'confirm' => true])->assertOk()->assertJsonPath('adminMetrics.sales', 15750);
        $this->assertDatabaseCount('orders', 3);
        $this->assertCount(3, array_filter(app(Store::class)->read()['orders'], fn ($o) => ! empty($o['adminHiddenAt'])));
        $audit = DB::table('admin_access_audit')->where('action', 'orders.hidden')->get();
        $this->assertCount(2, $audit);
        $this->assertSame(['second', 'third'], json_decode($audit[1]->details, true)['ids']);
        $token = app(Accounts::class)->issue('customer', $customer['id'])->getValue();
        $history = $this->withUnencryptedCookie('masal_customer', $token)->getJson('/api/state')->assertOk()->assertJsonCount(3, 'orders')->json('orders');
        foreach ($history as $order) {
            $this->assertArrayNotHasKey('adminHiddenAt', $order);
            $this->assertSame('delivered', $order['fulfillmentStatus']);
        }
        // Repeated deletion is idempotent and does not create new financial records.
        $current = app(Store::class)->read();
        $this->postJson('/api/admin/orders/hide', ['revision' => $current['revision'], 'ids' => ['first'], 'confirm' => true])->assertOk()->assertJsonPath('revision', $current['revision']);
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_confirmation_revision_and_entire_batch_are_validated_before_any_change(): void
    {
        [$s] = $this->fixture();
        $body = ['revision' => $s['revision'], 'ids' => ['first'], 'confirm' => true];
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/admin/orders/hide', $body)->assertStatus(401);
        $this->admin();
        $this->postJson('/api/admin/orders/hide', [...$body, 'revision' => 0])->assertStatus(409);
        $this->postJson('/api/admin/orders/hide', [...$body, 'confirm' => false])->assertStatus(400);
        foreach ([[], ['first', null], ['id' => 'first'], array_fill(0, 101, 'first')] as $ids) {
            $this->postJson('/api/admin/orders/hide', [...$body, 'ids' => $ids])->assertStatus(400);
        }
        $this->postJson('/api/admin/orders/hide', [...$body, 'ids' => ['first', 'missing']])->assertStatus(404);
        $this->assertSame($s['orders'], app(Store::class)->read()['orders']);
        $this->assertSame($s['revision'], app(Store::class)->read()['revision']);
    }

    public function test_view_and_status_permissions_cannot_hide_orders_without_delete_permission(): void
    {
        [$s] = $this->fixture();
        $role = (string) Str::uuid();
        $staff = (string) Str::uuid();
        DB::table('admin_roles')->insert(['id' => $role, 'name' => 'Order viewer', 'description' => '', 'permissions' => Store::json(['orders.view', 'orders.status']), 'unrestricted' => false, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('admin_staff')->insert(['id' => $staff, 'name' => 'Order fixture', 'username' => 'order-fixture', 'password' => 'not-used', 'role_id' => $role, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $token = app(Accounts::class)->issue('admin', null, $staff)->getValue();
        $this->withCredentials()->withUnencryptedCookie('masal_admin', $token)->withHeaders(['X-Fatya-Request' => '1']);
        $body = ['revision' => $s['revision'], 'ids' => ['first'], 'confirm' => true];
        $this->postJson('/api/admin/orders/hide', $body)->assertStatus(403);
        $this->assertSame($s['orders'], app(Store::class)->read()['orders']);
        DB::table('admin_roles')->where('id', $role)->update(['permissions' => Store::json(['orders.view', 'orders.delete'])]);
        $this->postJson('/api/admin/orders/hide', $body)->assertOk();
        $this->assertNotEmpty(app(Store::class)->read()['orders'][0]['adminHiddenAt']);
    }
}
