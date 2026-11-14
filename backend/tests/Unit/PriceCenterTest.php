<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\PriceCenter;
use App\Services\StockOrders;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PriceCenterTest extends TestCase
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
        request()->cookies->set('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        $state = app(Store::class)->read();
        $state['products'] = [['id' => 1, 'name' => 'Fixture', 'active' => true, 'unit' => 'IQD', 'values' => [5000, 10000], 'prices' => [5250.001, 11000], 'stock' => [0, 0]]];
        app(Store::class)->save($state);

        return ['revision' => $state['revision'], 'keys' => ['product:1:0'], 'mode' => 'percent', 'value' => '10', 'step' => '25', 'rounding' => 'nearest', 'minimum' => '1', 'reason' => 'Fixture revision'];
    }

    public function test_price_preview_is_read_only_and_apply_changes_only_selected_canonical_price_once(): void
    {
        $input = $this->fixture();
        $service = app(PriceCenter::class);
        $before = app(Store::class)->read();
        $preview = $service->preview($input);
        $this->assertSame('5775.000', $preview['changes'][0]['new']);
        $this->assertSame($before, app(Store::class)->read());
        $service->apply(['previewId' => $preview['previewId']]);
        $after = app(Store::class)->read();
        $this->assertSame('5775.000', $after['products'][0]['prices'][0]);
        $this->assertSame(11000, $after['products'][0]['prices'][1]);
        $this->assertSame($before['orders'], $after['orders']);
        $this->assertCount(1, $service->listing()['history']);
        try {
            $service->apply(['previewId' => $preview['previewId']]);
            $this->fail('Cannot replay an approved preview');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertDatabaseCount('procurement_audit', 1);
        Http::assertNothingSent();
    }

    public function test_percentage_rounds_once_at_the_explicit_step(): void
    {
        $input = $this->fixture();
        $state = app(Store::class)->read();
        $state['products'][0]['prices'][0] = '0.001';
        app(Store::class)->save($state);
        $preview = app(PriceCenter::class)->preview([...$input, 'revision' => $state['revision'], 'value' => '49.999', 'step' => '0.001', 'minimum' => '0.001']);
        $this->assertSame('0.001', $preview['changes'][0]['new']);
    }

    public function test_bulk_validation_is_atomic_and_rejects_a_changed_revision(): void
    {
        $input = $this->fixture();
        $service = app(PriceCenter::class);
        try {
            $service->preview([...$input, 'keys' => ['product:1:0', 'product:missing:0']]);
            $this->fail('Unknown row must reject the full preview');
        } catch (ApiError $e) {
            $this->assertSame(422, $e->status);
        }
        $preview = $service->preview($input);
        $state = app(Store::class)->read();
        $state['products'][0]['prices'][1] = 12000;
        app(Store::class)->save($state);
        try {
            $service->apply(['previewId' => $preview['previewId']]);
            $this->fail('A stale preview cannot change prices');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(5250.001, app(Store::class)->read()['products'][0]['prices'][0]);
        $this->assertDatabaseCount('procurement_audit', 0);
    }

    public function test_provider_floor_and_source_version_are_checked_without_rewriting_old_sales(): void
    {
        $input = $this->fixture();
        $id = 'd4fe416b-5551-429a-a2cd-cf8670c312b0';
        DB::table('digital_publications')->insert(['id' => $id, 'identity' => hash('sha256', 'fixture'), 'product_id' => '1', 'connection' => 'topup', 'provider_id' => 'bundle-01', 'snapshot' => json_encode(['title' => 'Fixture bundle', 'cost' => '4300.750']), 'retail' => '5250.001', 'active' => true, 'version' => 1, 'updated' => time()]);
        $service = app(PriceCenter::class);
        $input = [...$input, 'keys' => ['publication:'.$id], 'mode' => 'set', 'value' => '4300', 'step' => '0.001'];
        try {
            $service->preview($input);
            $this->fail('Provider floor cannot be bypassed');
        } catch (ApiError $e) {
            $this->assertSame(422, $e->status);
        }
        $preview = $service->preview([...$input, 'value' => '5300.125']);
        DB::table('digital_publications')->where('id', $id)->update(['version' => 2]);
        try {
            $service->apply(['previewId' => $preview['previewId']]);
            $this->fail('Concurrent provider edit must invalidate preview');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame('5250.001', StockOrders::money((string) DB::table('digital_publications')->value('retail')));
    }
}
