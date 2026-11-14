<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalManualPayments;
use App\Services\DigitalProviders;
use App\Services\DigitalPublications;
use App\Services\DigitalSales;
use App\Services\InventoryReports;
use App\Services\OrderFulfillment;
use App\Services\StockOrders;
use App\Services\Store;
use App\Services\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalPublicationTest extends TestCase
{
    use RefreshDatabase;

    private int $providerCost = 4500;

    private bool $purchaseTimeout = false;

    private bool $partialCatalog = false;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function fixture(): array
    {
        request()->cookies->set('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        config(['digital.purchases_enabled' => false, 'digital.manual_topup_enabled' => true, 'digital.topup.enabled' => true, 'digital.topup.key' => 'fixture-key', 'fatya.admin_password' => 'fixture-only-password-12345']);
        Http::preventStrayRequests();
        Http::fake(['*/products' => fn () => Http::response($this->catalog($this->providerCost)), '*/checkEligibility' => Http::response(['status' => true]), '*/transactions' => function () {
            if ($this->purchaseTimeout) {
                throw new ConnectionException('fixture timeout');
            }

            return Http::response(['status' => 'success', 'id' => 'manual-fixture-tx', 'logicalResource' => ['value' => '9647700000000']]);
        }]);
        $s = app(Store::class)->read();
        $s['categories'] = [['id' => 'cards', 'name' => 'Cards', 'active' => true]];
        $s['products'] = [['id' => 1, 'name' => 'Asiacell', 'en' => 'Asiacell', 'category' => 'Cards', 'active' => true, 'unit' => 'IQD', 'values' => [5000], 'prices' => [5250], 'stock' => [0]]];
        $s['customers'] = [Accounts::newCustomer(['name' => 'Fixture', 'email' => ''], '07700000000')];
        app(Store::class)->save($s);
        $c = app(DigitalProviders::class)->catalog('topup');

        return [$s, $c];
    }

    private function catalog(int $cost = 4500): array
    {
        if ($this->partialCatalog) {
            return ['status' => 'ok', 'products' => [], 'meta' => ['pagination' => ['pageCount' => 2]]];
        }

        return ['status' => 'ok', 'products' => [['id' => 70, 'type' => 'topup', 'title' => 'Parent', 'price' => 0, 'show2site' => 1, 'image' => '/media/parent.png'], ['id' => 71, 'parent_id' => 70, 'type' => 'topup', 'title' => 'Direct 5000', 'price' => $cost, 'show2site' => 1, 'description' => 'Fixture description', 'duration_days' => 7, 'customInfo' => 'stored privately'], ['id' => 71, 'type' => 'bundle', 'title' => 'Bundle 5000', 'price' => $cost, 'show2site' => 1]]];
    }

    private function publish(array $catalog, string $type = 'topup', bool $published = true, int $version = 0): array
    {
        return app(DigitalPublications::class)->save(['revision' => app(Store::class)->read()['revision'], 'provider' => 'topup', 'providerId' => '71', 'type' => $type, 'productId' => '1', 'retail' => '5250', 'published' => $published, 'version' => $version, 'catalogHash' => $catalog['hash']]);
    }

    private function order(): array
    {
        [$s,$c] = $this->fixture();
        $pub = $this->publish($c)['publications'][0];
        $b = ['productId' => 1, 'publicationId' => $pub['id'], 'denom' => 0, 'quantity' => 1, 'payment' => 'Manual', 'quotedAmount' => '5250', 'reference' => 'manual-request-01', 'digital' => ['mobile' => '07700000000', 'confirmMobile' => '07700000000']];
        $o = app(OrderFulfillment::class)->reserve($s['customers'][0], $b)['order'];

        return [$s, $c, $pub, $b, $o];
    }

    private function payment(array $o): array
    {
        return ['orderId' => $o['id'], 'password' => 'fixture-only-password-12345', 'amount' => '5250', 'received' => true, 'receiptReference' => 'cash-receipt-01', 'note' => 'Cash received in fixture only', 'requestId' => 'manual-approval-01'];
    }

    public function test_new_sale_keeps_its_service_snapshot_after_catalog_edits(): void
    {
        [$s, $catalog, $pub, $body, $order] = $this->order();
        $this->assertSame('Direct 5000', $order['saleSnapshot']['serviceTitle']);
        $this->assertSame('5250.000', $order['saleSnapshot']['total']);
        $this->assertNull($order['saleSnapshot']['denominationValue']);
        $state = app(Store::class)->read();
        $state['products'][0]['name'] = 'Changed after sale';
        $state['products'][0]['prices'][0] = 9999;
        app(Store::class)->save($state);
        $repeated = app(OrderFulfillment::class)->reserve($state['customers'][0], $body)['order'];
        $this->assertSame($order['saleSnapshot'], $repeated['saleSnapshot']);
        $this->assertSame($order['name'], $repeated['name']);
        $this->assertArrayNotHasKey('cost', $repeated['saleSnapshot']);
    }

    public function test_import_is_private_until_publication_and_equal_value_services_remain_distinct(): void
    {
        [$s,$c] = $this->fixture();
        app(DigitalPublications::class)->decorate($s);
        $this->assertArrayNotHasKey('providerServices', $s['products'][0]);
        $this->assertStringContainsString('encryptedSource', DB::table('digital_catalog')->value('snapshot'));
        $this->assertArrayNotHasKey('encryptedSource', app(DigitalPublications::class)->catalogs()[0]);
        $this->publish($c);
        $this->publish($c, 'bundle');
        $s = app(Store::class)->read();
        app(DigitalPublications::class)->decorate($s);
        $services = $s['products'][0]['providerServices'];
        $this->assertCount(2, $services);
        $this->assertNotSame($services[0]['id'], $services[1]['id']);
        $this->assertTrue($services[0]['purchasable']);
        $this->assertArrayNotHasKey('cost', $services[0]);
        $this->assertArrayNotHasKey('providerId', $services[0]);
        $direct = collect($services)->firstWhere('type', 'topup');
        $this->assertSame(['https://masal-co.com/media/parent.png'], $direct['images']);
        $this->assertSame('7', collect($direct['serviceInfo'])->firstWhere('label', 'duration_days')['value']);
        $this->assertSame('5250.000', StockOrders::money($direct['price']));
        $this->assertArrayNotHasKey('supplierDetails', $direct);
        $this->assertSame([5000], $s['products'][0]['values']);
        $this->assertSame([0], $s['products'][0]['stock']);
        $this->publish($c, 'topup', false, 1);
        $s = app(Store::class)->read();
        app(DigitalPublications::class)->decorate($s);
        $this->assertCount(1, $s['products'][0]['providerServices']);
        $remaining = collect(app(DigitalPublications::class)->listing())->firstWhere('published', true);
        DB::table('digital_catalog')->delete();
        app(DigitalPublications::class)->save(['revision' => app(Store::class)->read()['revision'], 'provider' => 'topup', 'id' => $remaining['id'], 'version' => $remaining['version'], 'published' => false]);
        $s = app(Store::class)->read();
        app(DigitalPublications::class)->decorate($s);
        $this->assertArrayNotHasKey('providerServices', $s['products'][0]);
    }

    public function test_manual_request_waits_then_admin_receipt_executes_exactly_once(): void
    {
        [$s,$c,$pub,$b,$o] = $this->order();
        $this->assertSame('pending', $o['paymentStatus']);
        $this->assertNull($o['value']);
        app(DigitalSales::class)->run();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transactions'));
        $payment = $this->payment($o);
        app(DigitalManualPayments::class)->confirm($payment);
        app(DigitalManualPayments::class)->confirm($payment);
        app(DigitalSales::class)->run();
        $this->assertSame('delivered', DB::table('digital_executions')->value('state'));
        $this->assertDatabaseCount('digital_manual_payments', 1);
        $this->assertDatabaseCount('payment_receipts', 1);
        $requests = Http::recorded(fn ($r) => str_ends_with($r->url(), '/transactions'));
        $this->assertCount(1, $requests);
        $this->assertSame('71', $requests->first()[0]['category']);
        $this->assertSame('Manual', DB::table('payment_receipts')->value('provider'));
        $retry = app(OrderFulfillment::class)->reserve($s['customers'][0], $b);
        $this->assertFalse($retry['created']);
    }

    public function test_catalog_lock_and_partial_responses_preserve_existing_snapshot(): void
    {
        [, $catalog] = $this->fixture();
        $lock = Cache::lock('digital-catalog:topup:all', 120);
        $this->assertTrue($lock->get());
        try {
            app(DigitalProviders::class)->catalog('topup');
            $this->fail('Concurrent sync accepted');
        } catch (ApiError $error) {
            $this->assertSame(409, $error->status);
        } finally {
            $lock->release();
        }
        $before = DB::table('digital_catalog')->value('snapshot');
        $this->partialCatalog = true;
        try {
            app(DigitalProviders::class)->catalog('topup');
            $this->fail('Partial catalog accepted');
        } catch (ApiError $error) {
            $this->assertSame(502, $error->status);
        }
        $this->assertSame($before, DB::table('digital_catalog')->value('snapshot'));
    }

    public function test_stale_checkout_price_cannot_create_a_differently_priced_order(): void
    {
        [$s,$c] = $this->fixture();
        $pub = $this->publish($c)['publications'][0];
        try {
            app(OrderFulfillment::class)->reserve($s['customers'][0], ['productId' => 1, 'publicationId' => $pub['id'], 'denom' => 0, 'quantity' => 1, 'payment' => 'Manual', 'quotedAmount' => '1', 'reference' => 'stale-price-01', 'digital' => ['mobile' => '07700000000', 'confirmMobile' => '07700000000']]);
            $this->fail('Stale price must not create an order');
        } catch (ApiError) {
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('digital_executions', 0);
    }

    public function test_bulk_publish_price_and_unpublish_touch_only_the_selected_identities(): void
    {
        [$s,$catalog] = $this->fixture();
        $body = ['revision' => app(Store::class)->read()['revision'], 'catalogHash' => $catalog['hash'], 'action' => 'publish', 'productId' => '1', 'priceMode' => 'markup', 'percent' => '10', 'flat' => '50', 'items' => [['providerId' => '71', 'type' => 'topup', 'version' => 0], ['providerId' => '71', 'type' => 'bundle', 'version' => 0]]];
        $result = app(DigitalPublications::class)->bulk($body);
        $this->assertSame(2, $result['changed']);
        $this->assertDatabaseCount('digital_publications', 2);
        $this->assertDatabaseCount('orders', 0);
        $rows = collect($result['publications'])->keyBy(fn ($p) => $p['snapshot']['type']);
        $this->assertSame('5000.000', StockOrders::money($rows['topup']['retail']));
        $body['revision'] = $result['revision'];
        $body['action'] = 'price';
        $body['priceMode'] = 'fixed';
        $body['retail'] = '6000';
        $body['items'] = [['providerId' => '71', 'type' => 'bundle', 'version' => 1]];
        $result = app(DigitalPublications::class)->bulk($body);
        $updated = collect($result['publications'])->keyBy(fn ($p) => $p['snapshot']['type']);
        $this->assertSame($rows['bundle']['id'], $updated['bundle']['id']);
        $this->assertTrue($updated['bundle']['published']);
        $this->assertSame('6000.000', StockOrders::money($updated['bundle']['retail']));
        $this->assertSame($rows['topup'], $updated['topup']);
        DB::table('digital_catalog')->delete();
        $body['revision'] = $result['revision'];
        $body['action'] = 'unpublish';
        $body['items'][0]['version'] = 2;
        app(DigitalPublications::class)->bulk($body);
        $updated = collect(app(DigitalPublications::class)->listing())->keyBy(fn ($p) => $p['snapshot']['type']);
        $this->assertFalse($updated['bundle']['published']);
        $this->assertTrue($updated['topup']['published']);
        $this->assertSame([5250], app(Store::class)->read()['products'][0]['prices']);
    }

    public function test_bulk_conflict_or_missing_retail_rolls_back_the_entire_selection(): void
    {
        [,$catalog] = $this->fixture();
        $this->publish($catalog);
        $before = app(DigitalPublications::class)->listing();
        $revision = app(Store::class)->read()['revision'];
        $body = ['revision' => $revision, 'catalogHash' => $catalog['hash'], 'action' => 'price', 'productId' => '1', 'priceMode' => 'fixed', 'retail' => '6000', 'items' => [['providerId' => '71', 'type' => 'topup', 'version' => 1], ['providerId' => '71', 'type' => 'bundle', 'version' => 99]]];
        try {
            app(DigitalPublications::class)->bulk($body);
            $this->fail('A later-row conflict must reject all changes');
        } catch (ApiError) {
        }
        $this->assertSame($before, app(DigitalPublications::class)->listing());
        $this->assertSame($revision, app(Store::class)->read()['revision']);
        $body['items'][1]['version'] = 0;
        $body['priceMode'] = 'existing';
        $body['action'] = 'publish';
        try {
            app(DigitalPublications::class)->bulk($body);
            $this->fail('Missing retail must not publish some of the selection');
        } catch (ApiError) {
        }
        $this->assertSame($before, app(DigitalPublications::class)->listing());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_no_password_or_amount_mismatch_can_approve_payment(): void
    {
        [,,,,$o] = $this->order();
        $p = $this->payment($o);
        foreach ([array_replace($p, ['password' => 'wrong']), array_replace($p, ['amount' => '1']), array_replace($p, ['received' => false])] as $bad) {
            try {
                app(DigitalManualPayments::class)->confirm($bad);
                $this->fail('Approval must fail');
            } catch (ApiError) {
            }
        }
        $this->assertDatabaseCount('digital_manual_payments', 0);
        $this->assertDatabaseCount('payment_receipts', 0);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transactions'));
        $this->getJson('/api/admin/procurement/digital')->assertStatus(401);
        $this->postJson('/api/admin/procurement/digital/manual-payment', $p, ['X-Fatya-Request' => '1'])->assertStatus(401);
    }

    public function test_changed_cost_blocks_receipt_without_partially_confirming(): void
    {
        [,,,,$o] = $this->order();
        $this->providerCost = 4600;
        try {
            app(DigitalManualPayments::class)->confirm($this->payment($o));
            $this->fail('Changed quote must fail');
        } catch (ApiError) {
        }
        $this->assertDatabaseCount('digital_manual_payments', 0);
        $this->assertSame('pending', DB::table('order_fulfillments')->value('payment_status'));
    }

    public function test_financial_dispatch_journal_prevents_replay_even_if_worker_state_is_reset(): void
    {
        [,,,,$order] = $this->order();
        app(DigitalManualPayments::class)->confirm($this->payment($order));
        $dispatch = DB::table('digital_dispatches')->sole();
        $this->assertSame('response_received', $dispatch->outcome);
        $this->assertSame(200, (int) $dispatch->http_status);
        $this->assertNotNull($dispatch->response_hash);
        $this->assertNotNull($dispatch->finished_at);
        $publicEvidence = app(DigitalSales::class)->executions()[0]['dispatches'][0];
        $this->assertSame(200, $publicEvidence['httpStatus']);
        $this->assertStringNotContainsString('fixture-key', json_encode($publicEvidence));
        DB::table('digital_executions')->where('order_id', $order['id'])->update(['state' => 'queued']);
        DB::table('order_fulfillments')->where('order_id', $order['id'])->update(['state' => 'processing']);
        app(DigitalSales::class)->run();
        $this->assertSame('financial_dispatch_already_recorded', DB::table('digital_executions')->value('reason'));
        $this->assertDatabaseCount('digital_dispatches', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/transactions')));
    }

    public function test_provider_and_second_precise_report_scopes_share_all_rows_and_totals(): void
    {
        [,,,,$order] = $this->order();
        app(DigitalManualPayments::class)->confirm($this->payment($order));
        $store = app(Store::class);
        $state = $store->read();
        $state['orders'][0]['createdAt'] = '2026-11-13T21:00:01+00:00';
        $state['orders'][0]['price'] = 1; // Summary must use the trusted fulfillment amount.
        $other = [...$state['orders'][0], 'id' => 'ORDER-other-fixture', 'reference' => 'other-fixture', 'digitalProvider' => 'rabiaa'];
        $other['saleSnapshot']['serviceType'] = 'standard';
        $state['orders'][] = $other;
        $store->save($state);
        foreach (['order_fulfillments', 'digital_executions'] as $table) {
            $copy = (array) DB::table($table)->where('order_id', $order['id'])->sole();
            $copy['order_id'] = $other['id'];
            if ($table === 'digital_executions') {
                $copy['provider'] = 'rabiaa';
            }
            DB::table($table)->insert($copy);
        }
        $reports = app(InventoryReports::class);
        $filters = ['provider' => 'topup', 'serviceType' => 'topup', 'from' => '2026-11-14T00:00:01', 'to' => '2026-11-14T00:00:01'];
        $table = $reports->read(['type' => 'digital', 'filters' => $filters]);
        $export = $reports->read(['type' => 'digital', 'filters' => $filters, 'all' => true]);
        $this->assertSame($table['rows'], $export['rows']);
        $this->assertSame($table['totals'], $export['totals']);
        $this->assertCount(1, $table['rows']);
        $this->assertSame($order['id'], $table['rows'][0]['id']);
        $this->assertSame('5250.000', $table['rows'][0]['amount']);
        $this->assertNull($table['rows'][0]['value']);
        $this->assertSame('Asia/Baghdad', $table['timezone']);
        $this->assertCount(0, $reports->read(['type' => 'digital', 'filters' => [...$filters, 'to' => '2026-11-14T00:00:00', 'from' => '2026-11-14T00:00:00']])['rows']);
        $this->assertCount(1, $reports->read(['type' => 'digital', 'filters' => ['provider' => 'rabiaa']])['rows']);
    }

    public function test_lost_purchase_response_stays_for_review_without_second_charge(): void
    {
        [,,,,$o] = $this->order();
        $this->purchaseTimeout = true;
        $p = $this->payment($o);
        app(DigitalManualPayments::class)->confirm($p);
        $this->assertSame('review', DB::table('digital_executions')->value('state'));
        app(DigitalManualPayments::class)->confirm($p);
        app(DigitalSales::class)->run();
        $this->assertDatabaseCount('digital_manual_payments', 1);
        $this->assertSame('review', DB::table('digital_executions')->value('state'));
        $this->assertSame('provider_transport_uncertain', DB::table('digital_executions')->value('reason'));
        $this->assertSame('transport_uncertain', DB::table('digital_dispatches')->value('outcome'));
        $this->assertDatabaseCount('digital_dispatches', 1);
    }

    public function test_unpublished_and_expired_orders_cannot_be_approved_and_manual_dto_is_not_a_bypass(): void
    {
        [,$c,,$b,$o] = $this->order();
        $this->publish($c, 'topup', false, 1);
        try {
            app(DigitalManualPayments::class)->confirm($this->payment($o));
            $this->fail('Unpublished');
        } catch (ApiError) {
        }
        try {
            app(OrderFulfillment::class)->confirm(new VerifiedPayment($o['id'], 'Manual', 'forged-ref', 'forged-event', '5250', 'IQD'));
            $this->fail('No trusted approval');
        } catch (ApiError) {
        }
        DB::table('order_fulfillments')->update(['expires' => time() - 1]);
        try {
            app(DigitalManualPayments::class)->confirm($this->payment($o));
            $this->fail('Expired');
        } catch (ApiError) {
        }
        $this->assertDatabaseCount('digital_manual_payments', 0);
        $this->assertDatabaseCount('payment_receipts', 0);
    }
}
