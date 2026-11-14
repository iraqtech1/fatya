<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\RabiaaActivations;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RabiaaActivationTest extends TestCase
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
        app(Store::class)->read();
        config(['digital.rabiaa.enabled' => true, 'digital.rabiaa.key' => 'fake-only-activation-key']);
        Http::preventStrayRequests();

        return ['idempotency_key' => 'activation-test-key-001', 'mac_address' => str_repeat('Ab9_/+=-', 150), 'active_code' => '000123456789'];
    }

    public function test_explicit_activation_preserves_long_identifier_and_does_not_create_a_sale(): void
    {
        $input = $this->fixture();
        Http::fake(['active.nojoomalrabiaa.com/*' => Http::response(['success' => true])]);
        $before = app(Store::class)->read();
        $service = app(RabiaaActivations::class);
        $result = $service->submit('customer:fixture', $input, 'fixture');
        $this->assertSame('succeeded', $result['status']);
        $this->assertSame($result['id'], $service->submit('customer:fixture', $input, 'fixture')['id']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['macAddress'] === $input['mac_address'] && $r['activeCode'] === '000123456789');
        $this->assertSame($before['orders'], app(Store::class)->read()['orders']);
        $this->assertDatabaseCount('digital_executions', 0);
        $this->assertDatabaseCount('payment_receipts', 0);
        $row = DB::table('rabiaa_activations')->first();
        $this->assertStringNotContainsString($input['active_code'], $row->encrypted_payload);
        $this->assertStringNotContainsString($input['active_code'], json_encode($service->listing('customer:fixture')));
        $this->assertCount(0, $service->listing('customer:other')['data']);
    }

    public function test_uncertain_activation_keeps_pin_locked_and_never_retries_the_provider(): void
    {
        $input = $this->fixture();
        Http::fake(['active.nojoomalrabiaa.com/*' => fn () => throw new ConnectionException('mock disconnect')]);
        $service = app(RabiaaActivations::class);
        $this->assertSame('review', $service->submit('customer:fixture', $input, 'fixture')['status']);
        $this->assertSame('review', $service->submit('customer:fixture', $input, 'fixture')['status']);
        $this->assertDatabaseCount('rabiaa_activations', 1);
        $this->assertNotNull(DB::table('rabiaa_activations')->value('active_pin_hash'));
        try {
            $service->submit('customer:other', [...$input, 'idempotency_key' => 'activation-test-key-002'], 'other');
            $this->fail('The same PIN must remain locked after an uncertain result');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertDatabaseCount('rabiaa_activations', 1);
    }

    public function test_customer_cannot_activate_an_order_owned_by_another_account(): void
    {
        $input = $this->fixture();
        $this->expectException(ApiError::class);
        try {
            app(RabiaaActivations::class)->submit('customer:other', [...$input, 'order_id' => 'another-order'], 'other');
        } finally {
            Http::assertNothingSent();
            $this->assertDatabaseCount('rabiaa_activations', 0);
        }
    }
}
