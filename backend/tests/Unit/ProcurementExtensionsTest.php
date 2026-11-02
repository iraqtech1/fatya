<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalConnections;
use App\Services\DigitalProviders;
use App\Services\DigitalSales;
use App\Services\ImportCategories;
use App\Services\OrderFulfillment;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use App\Services\Store;
use App\Services\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcurementExtensionsTest extends TestCase
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
        $s['products'] = [['id' => 1, 'name' => 'Fixture', 'en' => 'Asiacell', 'active' => true, 'category' => 'Cards', 'unit' => 'IQD', 'values' => [5000, 10000], 'prices' => [5250, 10400], 'stock' => [0, 0]]];
        $s['customers'] = [Accounts::newCustomer(['name' => 'Fixture', 'email' => ''], '07700000000')];
        app(Store::class)->save($s);

        return $s;
    }

    private function preview(string $pin = '0000012345'): array
    {
        $s = app(Store::class)->read();

        return app(StockOrders::class)->preview(['revision' => $s['revision'], 'supplier' => 'Supplier', 'source' => 'Invoice', 'lines' => [['productId' => '1', 'denom' => 0, 'cost' => '4500.125', 'rows' => [['pin' => $pin, 'serial' => 'SN-'.$pin, 'expiry' => now()->addYear()->toDateString()]]]]]);
    }

    private function submission(array $p, string $key = 'submit-fixture-01'): array
    {
        return ['previewId' => $p['previewId'], 'previewHash' => $p['previewHash'], 'requestId' => $key, 'excludeRejected' => false];
    }

    private function review(array $r, string $decision, string $key): array
    {
        return app(StockWorkflow::class)->review(['id' => $r['id'], 'version' => $r['version'], 'decision' => $decision, 'reason' => 'Fixture review', 'requestId' => $key]);
    }

    public function test_reference_mapping_is_explicit_and_does_not_seed_prices_or_stock(): void
    {
        $s = $this->fixture();
        $service = app(ImportCategories::class);
        $r = $service->read();
        $this->assertCount(28, $r['reference']);
        $this->assertCount(37, array_merge(...array_column($r['reference'], 'codes')));
        $service->save(['revision' => $s['revision'], 'mappings' => [['code' => 'EVS-E5K', 'productId' => '1', 'denom' => 0], ['code' => 'EV5', 'productId' => '1', 'denom' => 0]]]);
        $line = $service->resolve(['categoryCode' => 'E5K'], app(Store::class)->read());
        $this->assertSame('1', $line['productId']);
        $this->assertSame(0, $line['denom']);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->expectException(ApiError::class);
        $service->resolve(['categoryCode' => 'EV5', 'productId' => '1', 'denom' => 1], app(Store::class)->read());
    }

    public function test_pending_stock_review_is_encrypted_versioned_and_idempotent(): void
    {
        $this->fixture();
        $workflow = app(StockWorkflow::class);
        $workflow->savePolicy(['version' => 0, 'required' => true]);
        $p = $this->preview();
        $body = $this->submission($p);
        $pending = $workflow->submit($body);
        $this->assertSame('pending', $pending['status']);
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->assertSame($pending, $workflow->submit($body));
        $this->assertStringNotContainsString('0000012345', DB::table('stock_submission_chunks')->value('encrypted_cards'));
        $approved = $this->review($pending, 'approve', 'approve-review-01');
        $this->assertSame('approved', $approved['status']);
        $this->assertDatabaseCount('inventory_cards', 1);
        $this->assertSame('0000012345', Crypt::decryptString(DB::table('inventory_cards')->value('encrypted_code')));
        $this->assertSame($approved, $this->review($pending, 'approve', 'approve-review-01'));
        $this->assertDatabaseCount('stock_batches', 1);
    }

    public function test_return_and_resubmit_require_fresh_preview_and_version(): void
    {
        $this->fixture();
        $w = app(StockWorkflow::class);
        $r = $w->submit($this->submission($this->preview()));
        $returned = $this->review($r, 'return', 'return-review-01');
        $this->assertSame('returned', $returned['status']);
        $next = $w->submit($this->submission($this->preview('0000099999'), 'resubmit-key-01') + ['resubmitId' => $r['id'], 'version' => $returned['version']]);
        $this->assertSame($r['id'], $next['id']);
        $this->assertSame('pending', $next['status']);
        $this->review($next, 'reject', 'reject-review-01');
        $this->assertDatabaseCount('inventory_cards', 0);
    }

    public function test_changed_price_blocks_pending_approval_without_partial_inventory(): void
    {
        $this->fixture();
        $w = app(StockWorkflow::class);
        $r = $w->submit($this->submission($this->preview()));
        $s = app(Store::class)->read();
        $s['products'][0]['prices'][0] = 6000;
        app(Store::class)->save($s);
        try {
            $this->review($r, 'approve', 'price-review-01');
            $this->fail('Changed price accepted');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->assertSame('pending', DB::table('stock_submissions')->value('status'));
    }

    public function test_connection_keys_are_encrypted_never_echoed_and_blank_preserves_them(): void
    {
        $this->fixture();
        $service = app(DigitalConnections::class);
        $result = $service->save(['provider' => 'topup', 'version' => 0, 'active' => true, 'credential' => 'fixture-secret-key']);
        $this->assertStringNotContainsString('fixture-secret-key', Store::json($result));
        $this->assertNotSame('fixture-secret-key', DB::table('digital_connections')->value('credential'));
        $service->save(['provider' => 'topup', 'version' => 1, 'active' => true, 'credential' => '']);
        $this->assertSame('fixture-secret-key', app(DigitalProviders::class)->settings('topup')['key']);
        $this->expectException(ApiError::class);
        $service->save(['provider' => 'topup', 'version' => 0, 'active' => true, 'credential' => 'new-key', 'reason' => 'rotate']);
    }

    private function digital(string $provider = 'topup', string $type = 'topup'): array
    {
        $s = $this->fixture();
        config(['digital.purchases_enabled' => true, 'digital.'.$provider.'.enabled' => true, 'digital.'.$provider.'.key' => 'fixture-key']);
        $offer = ['providerId' => '71', 'title' => 'Service', 'type' => $type, 'cost' => '4500.000', 'available' => true, 'provinceId' => $provider === 'rabiaa' ? '2' : null];
        DB::table('digital_catalog')->insert(['connection' => $provider, 'snapshot' => Store::json(['offers' => [$offer]]), 'synced' => time()]);
        DB::table('digital_offers')->insert(['id' => 'c03d8095-0e20-4aa8-9d06-b72a4bd9e601', 'product_id' => '1', 'denom_index' => 0, 'connection' => $provider, 'provider_id' => '71', 'snapshot' => Store::json($offer), 'retail' => '5250.000', 'active' => true, 'updated' => time()]);
        $input = $provider === 'topup' ? ['mobile' => '07700000000', 'confirmMobile' => '07700000000'] : ($type === 'premium' ? ['firstName' => 'Test', 'lastName' => 'Fixture', 'phoneNumber' => '07700000000', 'address' => 'Fixture address', 'beinProvinceId' => '3'] : []);
        $body = ['productId' => 1, 'denom' => 0, 'quantity' => 1, 'payment' => 'Qi', 'reference' => 'digital-reference-01', 'digital' => $input];

        return [$s, app(OrderFulfillment::class)->reserve($s['customers'][0], $body)['order'], $body];
    }

    private function pay(array $o, string $amount = '5250.000'): array
    {
        return app(OrderFulfillment::class)->confirm(new VerifiedPayment($o['id'], 'Qi', 'fixture-payment-01', 'fixture-event-01', $amount, 'IQD'));
    }

    private function providerCatalog(string $provider = 'topup', string $type = 'standard'): array
    {
        return $provider === 'topup' ? ['status' => 'ok', 'products' => [['id' => 71, 'title' => 'Service', 'type' => 'topup', 'show2site' => 1, 'price' => 4500]]] : ['data' => [['catalogId' => 71, 'title' => 'Service', 'vendorPrice' => 4500, 'inStock' => true, 'packageType' => $type]]];
    }

    public function test_topup_waits_for_verified_payment_and_sends_once_with_no_fake_profit(): void
    {
        [$s,$o,$body] = $this->digital();
        Http::fake(['*/products' => Http::response($this->providerCatalog()), '*/checkEligibility' => Http::response(['status' => true]), '*/transactions' => Http::response(['status' => 'success', 'id' => 'tx-fixture-01', 'logicalResource' => ['value' => '9647700000000']])]);
        app(DigitalSales::class)->run();
        Http::assertNothingSent();
        $this->assertSame('processing', $this->pay($o)['outcome']);
        app(DigitalSales::class)->run();
        app(DigitalSales::class)->run();
        $this->assertSame('delivered', DB::table('digital_executions')->value('state'));
        $this->assertNull(DB::table('digital_executions')->value('actual_cost'));
        $order = app(Store::class)->read()['orders'][0];
        $this->assertFalse($order['codesAvailable']);
        $this->assertNull($order['profitBeforeFees']);
        $this->assertSame('tx-fixture-01', $order['providerReference']);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/transactions')));
        $this->assertSame($o['id'], app(OrderFulfillment::class)->reserve($s['customers'][0], $body)['order']['id']);
        $this->expectException(ApiError::class);
        app(OrderFulfillment::class)->reserve($s['customers'][0], [...$body, 'digital' => ['mobile' => '07800000000', 'confirmMobile' => '07800000000']]);
    }

    public function test_lost_purchase_response_stays_review_and_cannot_be_replayed_or_rotated(): void
    {
        [, $o] = $this->digital();
        Http::fake(['*/products' => Http::response($this->providerCatalog()), '*/checkEligibility' => Http::response(['status' => true]), '*/transactions' => fn () => throw new ConnectionException('fixture-timeout')]);
        $this->pay($o);
        app(DigitalSales::class)->run();
        app(DigitalSales::class)->run();
        $this->assertSame('review', DB::table('digital_executions')->value('state'));
        $this->assertSame('review', app(DigitalSales::class)->reconcile(['orderId' => $o['id']])['state']);
        $this->expectException(ApiError::class);
        app(DigitalConnections::class)->save(['provider' => 'topup', 'version' => 0, 'active' => true, 'credential' => 'rotated-key', 'reason' => 'fixture']);
    }

    public function test_invalid_payment_never_enqueues_provider_purchase(): void
    {
        [, $o] = $this->digital();
        $this->assertSame('review', $this->pay($o, '1.000')['outcome']);
        app(DigitalSales::class)->run();
        Http::assertNothingSent();
        $this->assertSame('awaiting_payment', DB::table('digital_executions')->value('state'));
    }

    public function test_rabiaa_hold_is_saved_before_purchase_and_receipt_is_private(): void
    {
        [$s,$o] = $this->digital('rabiaa', 'standard');
        $hold = ['holdToken' => '1f89a748-81bb-4e1c-9553-b51903487b66', 'cardId' => 9, 'heldAt' => now()->toIso8601String(), 'expiresInMinutes' => 5, 'vendorPrice' => 4500];
        Http::fake(['*/catalog*' => Http::response($this->providerCatalog('rabiaa')), '*/hold' => Http::response(['data' => $hold]), '*/purchase' => function ($r) use ($hold) {
            $row = DB::table('digital_executions')->first();
            $this->assertSame('purchase_started', $row->state);
            $this->assertSame($hold['holdToken'], json_decode(Crypt::decryptString($row->encrypted_evidence), true)['hold']['holdToken']);

            return Http::response(['data' => ['cardId' => 9, 'transactionId' => 15, 'vendorPrice' => 4499, 'code' => '000012345678', 'serialNumber' => '000123']]);
        }]);
        $this->pay($o);
        app(DigitalSales::class)->run();
        app(DigitalSales::class)->run();
        $this->assertSame('delivered', DB::table('digital_executions')->value('state'));
        $codes = app(OrderFulfillment::class)->codes($o['id'], $s['customers'][0]);
        $this->assertSame('000012345678', $codes['codes'][0]['code']);
        $this->assertSame('4499.000', StockOrders::money((string) DB::table('digital_executions')->value('actual_cost')));
        $this->assertStringNotContainsString('000012345678', Store::json(app(DigitalSales::class)->executions()));
        $this->assertCount(1, Http::recorded(fn ($r) => str_ends_with($r->url(), '/purchase')));
        $this->expectException(ApiError::class);
        app(OrderFulfillment::class)->codes($o['id'], ['id' => 'someone-else']);
    }

    public function test_premium_non_success_is_not_delivered(): void
    {
        [, $o] = $this->digital('rabiaa', 'premium');
        Http::fake(['*/catalog*' => Http::response($this->providerCatalog('rabiaa', 'premium')), '*/bein/provinces' => Http::response(['data' => [['id' => 3, 'title' => 'Fixture']]]), '*/hold' => Http::response(['data' => ['holdToken' => '1f89a748-81bb-4e1c-9553-b51903487b66', 'cardId' => 9, 'heldAt' => now()->toIso8601String(), 'expiresInMinutes' => 5, 'vendorPrice' => 4500]]), '*/purchase' => Http::response(['data' => ['cardId' => 9, 'transactionId' => 15, 'vendorPrice' => 4500, 'code' => 'fixture-pin', 'serialNumber' => 'fixture-serial', 'beinStatus' => 'pending']])]);
        $this->pay($o);
        app(DigitalSales::class)->run();
        $this->assertSame('review', DB::table('digital_executions')->value('state'));
        $this->assertSame('review', DB::table('order_fulfillments')->value('state'));
    }

    public function test_price_change_after_payment_stops_before_financial_post(): void
    {
        [, $o] = $this->digital();
        $catalog = $this->providerCatalog();
        $catalog['products'][0]['price'] = 9999;
        Http::fake(['*/products' => Http::response($catalog)]);
        $this->pay($o);
        app(DigitalSales::class)->run();
        $this->assertSame('review', DB::table('digital_executions')->value('state'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_successful_empty_catalog_disables_old_mapping_but_failure_preserves_it(): void
    {
        $this->digital();
        Http::fake(['*/products' => Http::sequence()->push('bad', 502)->push(['status' => 'ok', 'products' => []])]);
        try {
            app(DigitalProviders::class)->catalog('topup');
            $this->fail('Failed catalog accepted');
        } catch (ApiError $e) {
            $this->assertSame(502, $e->status);
        }
        $this->assertTrue((bool) DB::table('digital_offers')->value('active'));
        app(DigitalProviders::class)->catalog('topup');
        $this->assertFalse((bool) DB::table('digital_offers')->value('active'));
    }

    public function test_pending_and_direct_submission_retries_survive_policy_changes(): void
    {
        $this->fixture();
        $w = app(StockWorkflow::class);
        $p = $this->preview();
        $b = $this->submission($p);
        $direct = app(StockOrders::class)->approve($b);
        $w->savePolicy(['required' => true, 'version' => 0]);
        $this->assertSame($direct, app(StockOrders::class)->approve($b));
        $p = $this->preview('0000088888');
        $b = $this->submission($p, 'policy-retry-key');
        $p2 = app(StockOrders::class)->approve($b);
        $w->savePolicy(['required' => false, 'version' => 1]);
        $this->assertSame($p2, app(StockOrders::class)->approve($b));
    }

    public function test_new_admin_endpoints_require_session_and_json_write_protection(): void
    {
        $this->fixture();
        $this->putJson('/api/admin/procurement/digital/connections', ['provider' => 'topup'])->assertForbidden();
        $this->withHeaders(['X-Fatya-Request' => '1'])->putJson('/api/admin/procurement/digital/connections', ['provider' => 'topup'])->assertUnauthorized();
        $this->getJson('/api/admin/procurement/stock-orders')->assertUnauthorized();
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        $this->getJson('/api/admin/procurement/stock-orders')->assertOk()->assertJsonCount(28, 'reference');
        $this->postJson('/api/admin/procurement/digital/purchase', ['confirm' => true])->assertNotFound();
        Http::assertNothingSent();
    }
}
