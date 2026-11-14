<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\CardLifecycle;
use App\Services\StockOrderCatalog;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockOrderCatalogTest extends TestCase
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
        $s['categories'] = [['id' => 'c', 'name' => 'اتصالات', 'active' => true]];
        $s['companies'] = [['id' => 'asia', 'name' => 'آسياسيل', 'role' => 'manufacturer', 'active' => true], ['id' => 'zain', 'name' => 'زين', 'role' => 'manufacturer', 'active' => true]];
        $s['products'] = [['id' => 1, 'name' => 'آسياسيل', 'en' => 'Asiacell', 'category' => 'اتصالات', 'unit' => 'IQD', 'active' => true, 'manufacturerId' => 'asia', 'values' => [5000], 'prices' => [5250], 'stock' => [0], 'denomNames' => ['الاسم الأصلي'], 'denomImages' => ['cards/old.png']]];
        app(Store::class)->save($s);

        return $s;
    }

    private function line(array $overrides = []): array
    {
        return [...['productId' => '1', 'manufacturerId' => 'asia', 'denom' => -1, 'newValue' => '10000', 'newName' => 'رصيد 10000', 'categoryCode' => 'E10K', 'cost' => '9500.125', 'retail' => '11000.500', 'rows' => [['pin' => '000000000123', 'serial' => '0000001']]], ...$overrides];
    }

    private function preview(array $lines): array
    {
        return app(StockOrders::class)->preview(['revision' => app(Store::class)->read()['revision'], 'supplier' => 'Supplier', 'source' => 'Invoice', 'lines' => $lines]);
    }

    private function approve(array $preview): array
    {
        return app(StockOrders::class)->approve(['previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'requestId' => 'new-category-request', 'excludeRejected' => false]);
    }

    public function test_new_category_company_prices_and_stock_commit_together_once(): void
    {
        $before = $this->fixture();
        $p = $this->preview([$this->line()]);
        $this->assertTrue($p['lines'][0]['newDenomination']);
        $this->assertSame($before['products'], app(Store::class)->read()['products'], 'Preview must not change catalog');
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->assertDatabaseCount('stock_category_codes', 0);
        $result = $this->approve($p);
        $this->assertSame($result, $this->approve($p));
        $product = app(Store::class)->read()['products'][0];
        $this->assertEquals([5000, 10000], $product['values']);
        $this->assertEquals([5250, 11000.5], $product['prices']);
        $this->assertSame([0, 1], $product['stock']);
        $this->assertSame('asia', $product['manufacturerId']);
        $this->assertSame(1, $product['id']);
        $this->assertSame('الاسم الأصلي', $product['denomNames'][0]);
        $this->assertSame('cards/old.png', $product['denomImages'][0]);
        $this->assertSame('رصيد 10000', $product['denomNames'][1]);
        $this->assertDatabaseHas('stock_category_codes', ['scope' => 'manufacturer:asia', 'code' => 'E10K', 'product_id' => '1', 'denomination' => 10000]);
        $this->assertSame('000000000123', Crypt::decryptString(DB::table('inventory_cards')->value('encrypted_code')));
        $this->assertEquals(9500.125, DB::table('inventory_cards')->value('unit_cost'));
        $this->assertDatabaseCount('inventory_cards', 1);
    }

    public function test_existing_category_retail_is_updated_only_on_approval(): void
    {
        $this->fixture();
        $p = $this->preview([$this->line(['denom' => 0, 'categoryCode' => 'E5K', 'retail' => '5750'])]);
        $this->assertEquals(5250, app(Store::class)->read()['products'][0]['prices'][0]);
        $this->approve($p);
        $product = app(Store::class)->read()['products'][0];
        $this->assertEquals([5000], $product['values']);
        $this->assertEquals([5750], $product['prices']);
        $this->assertSame([1], $product['stock']);
    }

    public function test_reviewed_order_preserves_planned_new_category_without_early_creation(): void
    {
        $this->fixture();
        $w = app(StockWorkflow::class);
        $w->savePolicy(['version' => 0, 'required' => true]);
        $preview = $this->preview([$this->line()]);
        $pending = $w->submit(['previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'requestId' => 'historical-submission', 'excludeRejected' => false]);
        $this->assertEquals([5000], app(Store::class)->read()['products'][0]['values']);
        $this->assertDatabaseCount('inventory_cards', 0);
        $approved = $w->review(['id' => $pending['id'], 'version' => $pending['version'], 'decision' => 'approve', 'reason' => '', 'requestId' => 'review-new-category']);
        $this->assertSame('approved', $approved['status']);
        $this->assertEquals([5000, 10000], app(Store::class)->read()['products'][0]['values']);
        $this->assertDatabaseCount('inventory_cards', 1);
    }

    public function test_wrong_company_and_changed_catalog_cannot_add_stock(): void
    {
        $this->fixture();
        try {
            $this->preview([$this->line(['manufacturerId' => 'zain'])]);
            $this->fail('Wrong manufacturer accepted');
        } catch (ApiError $e) {
            $this->assertStringContainsString('الشركة', $e->getMessage());
        }
        $p = $this->preview([$this->line()]);
        $s = app(Store::class)->read();
        $s['products'][0]['prices'][0] = 5600;
        app(Store::class)->save($s);
        try {
            $this->approve($p);
            $this->fail('Stale preview accepted');
        } catch (ApiError $e) {
            $this->assertStringContainsString('المعاينة', $e->getMessage());
        }
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->assertEquals([5000], app(Store::class)->read()['products'][0]['values']);
    }

    public function test_two_files_for_same_new_category_do_not_duplicate_it(): void
    {
        $this->fixture();
        $p = $this->preview([$this->line(), $this->line(['rows' => [['pin' => '000000000124', 'serial' => '0000002']]])]);
        $this->assertSame(1, $p['categoryCount']);
        $this->approve($p);
        $product = app(Store::class)->read()['products'][0];
        $this->assertEquals([5000, 10000], $product['values']);
        $this->assertSame([0, 2], $product['stock']);
        $this->assertDatabaseCount('stock_category_codes', 1);
    }

    public function test_conflicting_prices_for_same_category_leave_catalog_and_stock_unchanged(): void
    {
        $before = $this->fixture();
        try {
            $this->preview([$this->line(), $this->line(['retail' => '12000', 'rows' => [['pin' => '000000000124', 'serial' => '0000002']]])]);
            $this->fail('Conflicting prices accepted');
        } catch (ApiError $e) {
            $this->assertStringContainsString('قيم مختلفة', $e->getMessage());
        }
        $this->assertSame($before['products'], app(Store::class)->read()['products']);
        $this->assertDatabaseCount('inventory_cards', 0);
        $this->assertDatabaseCount('stock_category_codes', 0);
    }

    public function test_unassigned_existing_card_gets_selected_company_on_approval(): void
    {
        $s = $this->fixture();
        $s['products'][0]['manufacturerId'] = '';
        app(Store::class)->save($s);
        $p = $this->preview([$this->line(['denom' => 0, 'categoryCode' => 'E5K'])]);
        $this->assertSame('', app(Store::class)->read()['products'][0]['manufacturerId']);
        $this->approve($p);
        $this->assertSame('asia', app(Store::class)->read()['products'][0]['manufacturerId']);
        $this->assertDatabaseHas('stock_category_codes', ['scope' => 'manufacturer:asia', 'code' => 'E5K']);
    }

    public function test_approval_adds_stock_immediately_even_with_an_old_review_policy(): void
    {
        $this->fixture();
        DB::table('procurement_settings')->where('id', 1)->update(['approval_required' => true]);
        $preview = $this->preview([$this->line()]);
        $this->assertFalse($preview['approvalRequired']);
        $result = $this->approve($preview);
        $this->assertSame('approved', $result['status']);
        $this->assertSame($result, $this->approve($preview));
        $this->assertSame([0, 1], app(Store::class)->read()['products'][0]['stock']);
        $this->assertDatabaseCount('stock_submissions', 0);
        $this->assertDatabaseCount('inventory_cards', 1);
    }

    public function test_legacy_submit_endpoint_also_imports_without_a_second_approval(): void
    {
        $this->fixture();
        DB::table('procurement_settings')->where('id', 1)->update(['approval_required' => true]);
        $preview = $this->preview([$this->line()]);
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);
        $body = ['previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'requestId' => 'legacy-direct-import', 'excludeRejected' => false];
        $this->postJson('/api/admin/procurement/stock-orders/submit', $body)->assertOk()->assertJsonPath('status', 'approved');
        $this->postJson('/api/admin/procurement/stock-orders/submit', $body)->assertOk()->assertJsonPath('status', 'approved');
        $this->assertDatabaseCount('stock_submissions', 0);
        $this->assertDatabaseCount('inventory_cards', 1);
    }

    public function test_no_expiry_is_explicit_and_ambiguous_dates_are_rejected_in_preview(): void
    {
        $state = $this->fixture();
        $line = $this->line(['expiry' => '2030-01-01', 'rows' => [['pin' => 'no-expiry-fixture', 'serial' => '01', 'expiry' => '', 'noExpiry' => true], ['pin' => 'ambiguous-fixture', 'serial' => '02', 'expiry' => '', 'expiryError' => 'Ambiguous']]]);
        $preview = app(StockOrders::class)->preview(['revision' => $state['revision'], 'supplier' => 'Fixture', 'source' => 'Dates fixture', 'lines' => [$line]]);
        $this->assertSame(1, $preview['rejected']);
        app(StockOrders::class)->approve(['requestId' => 'date-policy-fixture', 'previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'excludeRejected' => true]);
        $this->assertDatabaseCount('inventory_cards', 1);
        $this->assertNull(DB::table('inventory_cards')->value('expires_on'));
    }

    public function test_api_denomination_is_excluded_while_physical_denomination_of_same_product_can_import(): void
    {
        $state = $this->fixture();
        DB::table('digital_offers')->insert(['id' => 'fee735df-6f14-4118-9da6-43d69a2433f9', 'product_id' => '1', 'denom_index' => 0, 'connection' => 'topup', 'provider_id' => '71', 'snapshot' => '{}', 'retail' => 5250, 'active' => false, 'updated' => time()]);
        try {
            app(StockOrderCatalog::class)->prepare($this->line(['denom' => 0]), $state);
            $this->fail('API import accepted');
        } catch (ApiError $error) {
            $this->assertSame(422, $error->status);
        }
        $plan = app(StockOrderCatalog::class)->prepare($this->line(), $state);
        $this->assertSame(1, $plan['line']['denom']);
        $this->assertSame([5000, 10000.0], $state['products'][0]['values']);
    }

    public function test_card_lookup_links_import_evidence_without_exposing_pin_or_inventing_sales(): void
    {
        $state = $this->fixture();
        $preview = app(StockOrders::class)->preview(['revision' => $state['revision'], 'supplier' => 'Fixture', 'source' => 'Lookup fixture', 'lines' => [$this->line()]]);
        $batch = app(StockOrders::class)->approve(['requestId' => 'lookup-fixture', 'previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'excludeRejected' => false]);
        $lookup = app(CardLifecycle::class);
        $serial = $lookup->read(['query' => '0000001', 'kind' => 'serial']);
        $this->assertSame(1, $serial['count']);
        $this->assertSame($batch['id'], $serial['rows'][0]['batchId']);
        $this->assertNull($serial['rows'][0]['orderId']);
        $this->assertCount(1, $serial['rows'][0]['timeline']);
        $this->assertSame('استيراد إلى المخزن', $serial['rows'][0]['timeline'][0]['event']);
        $this->assertSame($serial['rows'], $lookup->read(['query' => 'E10K', 'kind' => 'importCode'])['rows']);
        $this->assertSame($serial['rows'], $lookup->read(['query' => '000000000123', 'kind' => 'pin'])['rows']);
        $this->assertStringNotContainsString('000000000123', json_encode($serial));
        $this->assertStringNotContainsString('000000000123', DB::table('procurement_audit')->pluck('details')->implode(' '));
    }
}
