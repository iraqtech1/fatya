<?php

namespace Tests\Unit;

use App\Services\Accounts;
use App\Services\DigitalProviders;
use App\Services\Inventory;
use App\Services\OrderFulfillment;
use App\Services\StockOrders;
use App\Services\Store;
use App\Services\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcurementTest extends TestCase
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
        Http::preventStrayRequests();
        $s = app(Store::class)->read();
        $s['categories'] = [['id' => 'c', 'name' => 'Cards', 'active' => true]];
        $s['products'] = [['id' => 1, 'name' => 'Fixture', 'active' => true, 'category' => 'Cards', 'unit' => 'IQD', 'values' => [5000], 'prices' => [5000.25], 'stock' => [0]]];
        $s['customers'] = [Accounts::newCustomer(['name' => 'Fixture', 'email' => ''], '07700000000')];
        app(Store::class)->save($s);

        return $s;
    }

    private function admin(): void
    {
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);
    }

    private function body(array $s, array $rows): array
    {
        return ['revision' => $s['revision'], 'supplier' => 'Fixture supplier', 'source' => 'Invoice 1', 'lines' => [['productId' => '1', 'denom' => 0, 'cost' => '4500.001', 'rows' => $rows]]];
    }

    private function approve(array $p, string $key = 'fixture-key-001', bool $exclude = false): array
    {
        return ['requestId' => $key, 'previewId' => $p['previewId'], 'previewHash' => $p['previewHash'], 'excludeRejected' => $exclude];
    }

    public function test_admin_authorization_and_missing_keys_fail_closed_without_network(): void
    {
        $this->fixture();
        $this->getJson('/api/admin/procurement/digital')->assertUnauthorized();
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/admin/procurement/stock-orders/preview', ['revision' => 0])->assertUnauthorized();
        $this->admin();
        $this->getJson('/api/admin/procurement/digital')->assertOk()->assertJsonPath('purchaseEnabled', false);
        $this->postJson('/api/admin/procurement/digital/catalog', ['provider' => 'topup'])->assertStatus(409);
        $this->postJson('/api/admin/procurement/digital/purchase', ['confirm' => true])->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_preview_is_non_mutating_private_and_approval_is_idempotent(): void
    {
        $s = $this->fixture();
        $rows = [['pin' => '0000123456789012', 'serial' => '000001'], ['pin' => '0000123456789013', 'serial' => '000002']];
        $p = app(StockOrders::class)->preview($this->body($s, $rows));
        $this->assertSame('9000.002', $p['costTotal']);
        $this->assertSame('10000.500', $p['nominalTotal']);
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->assertStringNotContainsString('0000123456789012', json_encode($p));
        $this->assertStringNotContainsString('0000123456789012', DB::table('stock_order_previews')->value('encrypted_draft'));
        $b = $this->approve($p);
        $a = app(StockOrders::class)->approve($b);
        $this->assertSame($a, app(StockOrders::class)->approve($b));
        $this->assertDatabaseCount('inventory_cards', 2);
        $this->assertDatabaseCount('stock_batches', 1);
        $this->assertSame('0000123456789012', Crypt::decryptString(DB::table('inventory_cards')->orderBy('id')->value('encrypted_code')));
        $this->assertSame('000001', DB::table('inventory_cards')->orderBy('id')->value('serial'));
        $this->admin();
        $this->postJson('/api/admin/procurement/stock-orders/approve', [...$b, 'excludeRejected' => true])->assertConflict();
    }

    public function test_duplicates_expiry_numeric_pin_and_explicit_exclusion(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 28));
        $s = $this->fixture();
        $p = app(StockOrders::class)->preview($this->body($s, [
            ['pin' => '00001', 'serial' => '0001'], ['pin' => '00001'], ['pin' => 12345],
            ['pin' => 'expired', 'expiry' => '2026-10-27'], ['pin' => 'warn', 'expiry' => '2026-02-31'],
        ]));
        $this->assertSame(2, $p['quantity']);
        $this->assertSame(3, $p['rejected']);
        $this->assertCount(1, $p['warnings']);
        $this->admin();
        $this->postJson('/api/admin/procurement/stock-orders/approve', $this->approve($p))->assertStatus(422);
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->postJson('/api/admin/procurement/stock-orders/approve', $this->approve($p, exclude: true))->assertOk();
        $s = app(Store::class)->read();
        $p = app(StockOrders::class)->preview($this->body($s, [['pin' => '00001'], ['pin' => 'another', 'serial' => '0001']]));
        $this->assertSame(0, $p['quantity']);
        $this->assertSame(2, $p['rejected']);
    }

    public function test_changed_revision_or_expired_preview_requires_new_preview(): void
    {
        $s = $this->fixture();
        $p = app(StockOrders::class)->preview($this->body($s, [['pin' => 'fixture']]));
        app(Store::class)->save($s);
        $this->admin();
        $this->postJson('/api/admin/procurement/stock-orders/approve', $this->approve($p))->assertConflict();
        $p = app(StockOrders::class)->preview($this->body($s, [['pin' => 'fixture']]));
        DB::table('stock_order_previews')->update(['expires' => time() - 1]);
        $this->postJson('/api/admin/procurement/stock-orders/approve', $this->approve($p))->assertConflict();
        $this->assertDatabaseCount('inventory_cards', 0);
    }

    public function test_expired_cards_are_not_available_and_cost_profit_are_admin_only(): void
    {
        $s = $this->fixture();
        $p = app(StockOrders::class)->preview($this->body($s, [['pin' => 'fixture-financial']]));
        app(StockOrders::class)->approve($this->approve($p));
        $customer = $s['customers'][0];
        $o = app(OrderFulfillment::class)->reserve($customer, ['productId' => 1, 'denom' => 0, 'quantity' => 1, 'payment' => 'Qi', 'reference' => 'fixture-sale'])['order'];
        app(OrderFulfillment::class)->confirm(new VerifiedPayment($o['id'], 'Qi', 'fixture-paid', 'fixture-event', '5000.250', 'IQD'));
        $sale = app(Store::class)->read()['orders'][0];
        $this->assertSame('4500.001', $sale['costTotal']);
        $this->assertSame('500.249', $sale['profitBeforeFees']);
        $this->withCredentials()->withUnencryptedCookie('masal_customer', app(Accounts::class)->issue('customer', $customer['id'])->getValue());
        $state = $this->getJson('/api/state')->assertOk()->json();
        $this->assertArrayNotHasKey('costTotal', $state['orders'][0]);
        $this->assertArrayNotHasKey('profitBeforeFees', $state['orders'][0]);
        DB::table('inventory_cards')->update(['reserved_order_id' => null, 'sold_order_id' => null, 'expires_on' => now()->subDay()->toDateString()]);
        $s = app(Store::class)->read();
        app(Inventory::class)->refreshStock($s);
        $this->assertSame(0, $s['products'][0]['stock'][0]);
    }

    public function test_topup_balance_large_identifiers_filtering_and_strict_eligibility(): void
    {
        $this->fixture();
        config(['digital.topup.enabled' => true, 'digital.topup.key' => 'fixture-key']);
        Http::fake([
            'masal-co.com/api/v1/inventory' => Http::response('100000'),
            'masal-co.com/api/v1/products' => Http::response('{"products":[{"id":9000000000000000001,"title":"Shown","type":"topup","show2site":1,"price":6000},{"id":2,"title":"Group","type":"bundle","show2site":1,"price":0},{"id":3,"title":"Hidden","type":"bill","show2site":0,"price":1}]}'),
            'masal-co.com/api/v1/checkEligibility' => Http::response(['status' => 'true']),
        ]);
        $service = app(DigitalProviders::class);
        $this->assertSame('100000.000', $service->balance()['balance']);
        $catalog = $service->catalog('topup');
        $this->assertCount(2, $catalog['offers']);
        $this->assertSame('9000000000000000001', $catalog['offers'][0]['providerId']);
        $this->assertFalse($catalog['offers'][1]['available']);
        $result = $service->eligibility(['mobile' => '07700000000', 'confirmMobile' => '+9647700000000', 'providerId' => '9000000000000000001']);
        $this->assertFalse($result['eligible']);
        Http::assertSent(fn ($r) => $r->url() === 'https://masal-co.com/api/v1/checkEligibility' && $r['mobile'] === '9647700000000' && $r->hasHeader('x-api-key', 'fixture-key'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/transactions'));
    }

    public function test_rabiaa_catalog_is_scoped_to_province_and_saved_mapping_uses_current_snapshot(): void
    {
        $s = $this->fixture();
        config(['digital.rabiaa.enabled' => true, 'digital.rabiaa.key' => 'fixture-key']);
        Http::fake(['*' => Http::response(['data' => [['catalogId' => 71, 'title' => 'Card', 'vendorPrice' => 4300.75, 'inStock' => true, 'packageType' => 'standard']]])]);
        $catalog = app(DigitalProviders::class)->catalog('rabiaa', '2');
        $this->assertSame('4300.750', $catalog['offers'][0]['cost']);
        $this->admin();
        $b = ['revision' => $s['revision'], 'provider' => 'rabiaa', 'providerId' => '71', 'catalogHash' => $catalog['hash'], 'productId' => '1', 'denom' => 0, 'retail' => '5000.250', 'active' => true];
        $this->putJson('/api/admin/procurement/digital/offers', [...$b, 'catalogHash' => 'wrong'])->assertConflict();
        $this->putJson('/api/admin/procurement/digital/offers', $b)->assertOk()->assertJsonPath('offers.0.retail', '5000.250');
        $this->assertDatabaseCount('digital_offers', 1);
        $this->getJson('/api/state')->assertOk()->assertJsonCount(0, 'suppliers');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/vendor/catalog?provinceId=2'));
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_unapproved_host_cannot_receive_provider_key(): void
    {
        $this->fixture();
        config(['digital.topup.enabled' => true, 'digital.topup.key' => 'fixture-key', 'digital.topup.base' => 'https://example.com']);
        $this->admin();
        $this->getJson('/api/admin/procurement/digital/balance')->assertConflict();
        Http::assertNothingSent();
    }

    public function test_maximum_order_size_and_default_expiry_are_enforced(): void
    {
        $s = $this->fixture();
        $rows = array_map(fn ($i) => ['pin' => 'bulk-fixture-'.str_pad((string) $i, 7, '0', STR_PAD_LEFT)], range(1, 50000));
        $body = $this->body($s, $rows);
        $body['lines'][0]['expiry'] = now()->addYear()->toDateString();
        $body['lines'][0]['rows'][0]['expiry'] = '';
        $p = app(StockOrders::class)->preview($body);
        $this->assertSame(50000, $p['quantity']);
        $this->assertSame('225000050.000', $p['costTotal']);
        app(StockOrders::class)->approve($this->approve($p));
        $this->assertDatabaseCount('inventory_cards', 50000);
        $this->assertSame($body['lines'][0]['expiry'], DB::table('inventory_cards')->value('expires_on'));
        $this->admin();
        $this->postJson('/api/admin/procurement/stock-orders/preview', $this->body(app(Store::class)->read(), [...$rows, ['pin' => 'too-many']]))->assertStatus(422);
    }
}
