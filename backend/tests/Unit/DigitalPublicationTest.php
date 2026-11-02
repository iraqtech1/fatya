<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalManualPayments;
use App\Services\DigitalProviders;
use App\Services\DigitalPublications;
use App\Services\DigitalSales;
use App\Services\OrderFulfillment;
use App\Services\Store;
use App\Services\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalPublicationTest extends TestCase
{
    use RefreshDatabase;

    private int $providerCost = 4500;

    private bool $purchaseTimeout = false;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function fixture(): array
    {
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
        return ['status' => 'ok', 'products' => [['id' => 71, 'type' => 'topup', 'title' => 'Direct 5000', 'price' => $cost, 'show2site' => 1, 'description' => 'Fixture description', 'customInfo' => 'stored privately'], ['id' => 71, 'type' => 'bundle', 'title' => 'Bundle 5000', 'price' => $cost, 'show2site' => 1]]];
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
