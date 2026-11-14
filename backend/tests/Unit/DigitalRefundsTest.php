<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalHistory;
use App\Services\DigitalRefunds;
use App\Services\InventoryReports;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalRefundsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function fixture(bool $delivered = false): array
    {
        Http::preventStrayRequests();
        config(['fatya.admin_password' => 'refund-fixture-password-12345', 'fatya.takeed_key' => null]);
        request()->cookies->set('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        $state = app(Store::class)->read();
        $customer = Accounts::newCustomer(['name' => 'Fixture', 'email' => ''], '07700000000');
        $state['customers'] = [$customer];
        $state['products'] = [['id' => 1, 'name' => 'Fixture', 'active' => true, 'unit' => 'IQD', 'values' => [5000], 'prices' => [5250.001], 'stock' => [0]]];
        $state['orders'] = [['id' => 'ORDER-refund-fixture', 'reference' => 'refund-fixture', 'customerId' => $customer['id'], 'customer' => 'Fixture', 'productId' => 1, 'name' => 'Frozen original sale', 'quantity' => 1, 'value' => 5000, 'price' => '5250.001', 'date' => '2026-11-14', 'createdAt' => '2026-11-14T10:01:02+03:00', 'digitalProvider' => 'topup', 'status' => $delivered ? 'مكتمل' : 'قيد المراجعة', 'costTotal' => $delivered ? '4500.001' : null, 'profitBeforeFees' => $delivered ? '750.000' : null]];
        app(Store::class)->save($state);
        DB::table('order_fulfillments')->insert(['order_id' => 'ORDER-refund-fixture', 'provider' => 'Manual', 'state' => $delivered ? 'delivered' : 'review', 'payment_status' => 'confirmed', 'amount' => '5250.001', 'currency' => 'IQD', 'quantity' => 1, 'expires' => time() + 600, 'delivered_at' => $delivered ? time() : null]);
        DB::table('digital_executions')->insert(['order_id' => 'ORDER-refund-fixture', 'provider' => 'topup', 'offer_id' => 'fixture-offer', 'connection_version' => 1, 'state' => $delivered ? 'delivered' : 'failed', 'reason' => $delivered ? null : 'provider_rejected', 'tracking_version' => 1, 'journal_version' => 1, 'dispatch_started' => time(), 'encrypted_input' => Crypt::encryptString(json_encode(['offer' => ['type' => 'topup']])), 'encrypted_evidence' => $delivered ? Crypt::encryptString(json_encode(['companyRefund' => ['confirmed' => true]])) : null, 'quoted_cost' => '4500.001', 'actual_cost' => $delivered ? '4500.001' : null, 'request_hash' => str_repeat('a', 64), 'updated' => time()]);

        return ['orderId' => 'ORDER-refund-fixture', 'password' => 'refund-fixture-password-12345', 'requestId' => 'refund-fixture-001', 'refundReference' => 'refund-receipt-001', 'returned' => true, 'note' => 'Mock proof of an actual manual repayment', 'amount' => '2000.001'];
    }

    public function test_partial_then_full_refund_is_idempotent_and_never_exceeds_the_verified_payment(): void
    {
        $input = $this->fixture();
        $service = app(DigitalRefunds::class);
        $this->assertSame('partial', $service->settle($input)['refundStatus']);
        $this->assertTrue($service->settle($input)['duplicate']);
        $this->assertDatabaseCount('digital_refund_entries', 1);
        try {
            $service->settle([...$input, 'requestId' => 'refund-fixture-too-large', 'refundReference' => 'refund-too-large', 'amount' => '4000.000']);
            $this->fail('Cannot return more than the payment');
        } catch (ApiError $e) {
            $this->assertSame(422, $e->status);
        }
        $last = [...$input, 'requestId' => 'refund-fixture-002', 'refundReference' => 'refund-receipt-002', 'amount' => '3250.000'];
        $this->assertSame('settled', $service->settle($last)['refundStatus']);
        $this->assertTrue($service->settle($last)['duplicate']);
        $this->assertDatabaseCount('digital_refund_entries', 2);
        $this->assertSame('5250.001', DigitalRefunds::totals()['ORDER-refund-fixture']['amount']);
        $summary = app(DigitalHistory::class)->read([])['summary'];
        $this->assertSame('0', $summary['sales']);
        $this->assertSame('0', $summary['net_sales']);
        $this->assertSame('5250.001', $summary['refunded_amount']);
        Http::assertNothingSent();
    }

    public function test_refund_of_a_delivered_sale_preserves_original_price_and_cost_and_changes_net_revenue_once(): void
    {
        $input = $this->fixture(true);
        $service = app(DigitalRefunds::class);
        $service->settle($input);
        $history = app(DigitalHistory::class)->read([]);
        $this->assertSame('5250.001', $history['data'][0]['retail']);
        $this->assertSame('3250.000', $history['data'][0]['net_revenue']);
        $this->assertSame('-1250.001', $history['data'][0]['profit']);
        $this->assertSame('750.000', app(Store::class)->read()['orders'][0]['profitBeforeFees']);
        $report = app(InventoryReports::class)->read(['type' => 'sales', 'all' => true]);
        $this->assertSame('2000.001', $report['totals']['refundedAmount']);
        $this->assertSame('3250.000', $report['totals']['netDeliveredAmount']);
        $this->assertSame('-1250.001', $report['totals']['deliveredGrossProfit']);
    }

    public function test_manual_stock_repayment_requires_its_own_path_and_never_restocks_a_revealed_card(): void
    {
        $input = $this->fixture(true);
        DB::table('digital_executions')->delete();
        $state = app(Store::class)->read();
        unset($state['orders'][0]['digitalProvider']);
        app(Store::class)->save($state);
        $card = DB::table('inventory_cards')->insertGetId(['product_id' => '1', 'denomination' => 5000, 'code_hash' => hash('sha256', 'fake-sold-pin'), 'encrypted_code' => Crypt::encryptString('fake-sold-pin'), 'sold_order_id' => 'ORDER-refund-fixture', 'unit_cost' => '4500.001']);
        try {
            app(DigitalRefunds::class)->settle($input);
            $this->fail('The digital-only path cannot settle stock orders');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        app(DigitalRefunds::class)->settle($input, true);
        $this->assertSame('ORDER-refund-fixture', DB::table('inventory_cards')->where('id', $card)->value('sold_order_id'));
        $this->assertSame('2000.001', DigitalRefunds::totals()['ORDER-refund-fixture']['amount']);
        $this->assertSame('750.000', app(Store::class)->read()['orders'][0]['profitBeforeFees']);
        Http::assertNothingSent();
    }
}
