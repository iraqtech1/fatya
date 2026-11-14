<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalProviders;
use App\Services\ImportCategories;
use App\Services\ImportFileReader;
use App\Services\Inventory;
use App\Services\InventoryExports;
use App\Services\InventoryReports;
use App\Services\OrderFulfillment;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class InventoryControlsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'fixture-export-password-only';

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function stock(int $count = 3): array
    {
        request()->cookies->set('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        Http::preventStrayRequests();
        config(['fatya.admin_password' => self::PASSWORD]);
        $s = app(Store::class)->read();
        $s['categories'] = [['id' => 'c', 'name' => 'Cards', 'active' => true]];
        $s['products'] = [['id' => 1, 'name' => 'Fixture', 'en' => 'Fixture', 'unit' => 'IQD', 'active' => true, 'category' => 'Cards', 'values' => [5000], 'prices' => [5250], 'stock' => [0]]];
        $s['customers'] = [Accounts::newCustomer(['name' => 'Fixture', 'email' => ''], '07700000000')];
        app(Store::class)->save($s);
        $rows = array_map(fn ($i) => ['pin' => '00000000000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'serial' => 'SER-'.$i, 'sourceRow' => $i + 4], range(1, $count));
        $p = app(StockOrders::class)->preview(['revision' => $s['revision'], 'supplier' => 'Supplier', 'source' => 'Invoice', 'lines' => [['productId' => '1', 'denom' => 0, 'cost' => '4500.001', 'fileName' => 'cards.csv', 'rows' => $rows]]]);

        return app(StockOrders::class)->approve(['requestId' => 'inventory-fixture', 'previewId' => $p['previewId'], 'previewHash' => $p['previewHash'], 'excludeRejected' => false]);
    }

    private function request(string $kind = 'copy', int $qty = 1): array
    {
        return ['requestId' => 'export-'.$kind, 'kind' => $kind, 'quantity' => $qty, 'reason' => 'Fixture audit', 'password' => self::PASSWORD, 'filters' => ['status' => 'available', 'productId' => '1']];
    }

    public function test_copy_is_private_repeatable_and_does_not_consume_stock(): void
    {
        $this->stock();
        $service = app(InventoryExports::class);
        $r = $service->create($this->request());
        $this->assertSame($r, $service->create($this->request()));
        $this->assertStringNotContainsString('0000000000000001', json_encode($r));
        $b = ['id' => $r['id'], 'password' => self::PASSWORD];
        $a = $service->download($b);
        $this->assertSame($a, $service->download($b));
        $this->assertSame('0000000000000001', $a['rows'][0]['pin']);
        $s = app(Store::class)->read();
        app(Inventory::class)->refreshStock($s);
        $this->assertSame(3, $s['products'][0]['stock'][0]);
        $this->assertStringNotContainsString($a['rows'][0]['pin'], DB::table('procurement_audit')->pluck('details')->implode(' '));
    }

    public function test_withdrawal_requires_review_is_once_and_excludes_reservation(): void
    {
        $this->stock();
        $service = app(InventoryExports::class);
        $r = $service->create($this->request('withdrawal', 2));
        $this->assertSame('pending', $r['status']);
        $this->assertSame(0, DB::table('inventory_cards')->whereNotNull('withdrawal_id')->count());
        $r = $service->review(['id' => $r['id'], 'version' => 0, 'decision' => 'approve', 'password' => self::PASSWORD]);
        $this->assertSame(2, DB::table('inventory_cards')->whereNotNull('withdrawal_id')->count());
        $s = app(Store::class)->read();
        $this->assertSame(1, $s['products'][0]['stock'][0]);
        $this->assertCount(2, $service->download(['id' => $r['id'], 'password' => self::PASSWORD])['rows']);
        $service->download(['id' => $r['id'], 'password' => self::PASSWORD]);
        $this->assertSame(2, DB::table('inventory_cards')->whereNotNull('withdrawal_id')->count());
        try {
            $service->review(['id' => $r['id'], 'version' => 0, 'decision' => 'approve', 'password' => self::PASSWORD]);
            $this->fail();
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->expectException(ApiError::class);
        app(OrderFulfillment::class)->reserve($s['customers'][0], ['productId' => 1, 'denom' => 0, 'quantity' => 2, 'payment' => 'Qi', 'reference' => 'withdrawal-check']);
    }

    public function test_reserved_card_cannot_be_withdrawn_after_request_was_created(): void
    {
        $this->stock();
        $service = app(InventoryExports::class);
        $r = $service->create($this->request('withdrawal', 2));
        $s = app(Store::class)->read();
        app(OrderFulfillment::class)->reserve($s['customers'][0], ['productId' => 1, 'denom' => 0, 'quantity' => 1, 'payment' => 'Qi', 'reference' => 'fixture-reservation']);
        try {
            $service->review(['id' => $r['id'], 'version' => 0, 'decision' => 'approve', 'password' => self::PASSWORD]);
            $this->fail();
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $this->assertSame(0, DB::table('inventory_cards')->whereNotNull('withdrawal_id')->count());
    }

    public function test_expired_download_can_be_renewed_without_new_withdrawal(): void
    {
        $this->stock();
        $service = app(InventoryExports::class);
        $r = $service->create($this->request('withdrawal'));
        $r = $service->review(['id' => $r['id'], 'version' => 0, 'decision' => 'approve', 'password' => self::PASSWORD]);
        DB::table('inventory_exports')->where('id', $r['id'])->update(['expires' => time() - 1]);
        try {
            $service->download(['id' => $r['id'], 'password' => self::PASSWORD]);
            $this->fail();
        } catch (ApiError $e) {
            $this->assertSame(410, $e->status);
        }
        $service->renew(['id' => $r['id'], 'version' => $r['version'], 'password' => self::PASSWORD]);
        $this->assertCount(1, $service->download(['id' => $r['id'], 'password' => self::PASSWORD])['rows']);
        $this->assertSame(1, DB::table('inventory_cards')->whereNotNull('withdrawal_id')->count());
    }

    public function test_exports_require_admin_session_and_separate_password_confirmation(): void
    {
        $this->stock();
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/admin/procurement/exports', $this->request())->assertUnauthorized();
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue());
        $this->postJson('/api/admin/procurement/exports', [...$this->request(), 'password' => 'incorrect'])->assertForbidden();
        config(['fatya.code_export_enabled' => false]);
        $this->postJson('/api/admin/procurement/exports', $this->request())->assertForbidden();
    }

    public function test_reports_export_all_pages_same_filters_and_never_pin(): void
    {
        $this->stock(61);
        $service = app(InventoryReports::class);
        $a = $service->read(['type' => 'inventory', 'filters' => ['productId' => '1']]);
        $b = $service->read(['type' => 'inventory', 'filters' => ['productId' => '1'], 'all' => true]);
        $this->assertCount(50, $a['rows']);
        $this->assertCount(61, $b['rows']);
        $this->assertSame($a['totals'], $b['totals']);
        $this->assertSame(61, $a['totals']['available']);
        $this->assertSame('274500.061', $a['totals']['availableCostKnown']);
        $this->assertStringNotContainsString('0000000000000001', json_encode($b));
        $this->assertSame(5, $b['rows'][0]['sourceRow']);
    }

    public function test_baghdad_date_boundaries_and_unknown_cost_do_not_invent_profit(): void
    {
        $this->stock();
        DB::table('inventory_cards')->where('id', 1)->update(['imported_at' => strtotime('2030-01-01 21:00:00 UTC'), 'unit_cost' => null]);
        $r = app(InventoryReports::class)->read(['filters' => ['from' => '2030-01-02', 'to' => '2030-01-02'], 'all' => true]);
        $this->assertCount(1, $r['rows']);
        $this->assertNull($r['rows'][0]['cost']);
        $this->assertSame(1, $r['totals']['availableUnknownCost']);
    }

    public function test_legacy_import_does_not_bypass_approval_policy(): void
    {
        $this->stock();
        DB::table('procurement_settings')->where('id', 1)->update(['approval_required' => true]);
        $s = app(Store::class)->read();
        $this->expectException(ApiError::class);
        app(Inventory::class)->import($s, ['name' => 'legacy', 'rows' => [['product_id' => 1, 'denomination' => 5000, 'code' => 'must-not-add']]]);
    }

    public function test_pending_report_keeps_rejected_counts_after_approval(): void
    {
        $this->stock();
        DB::table('procurement_settings')->where('id', 1)->update(['approval_required' => true]);
        $s = app(Store::class)->read();
        $p = app(StockOrders::class)->preview(['revision' => $s['revision'], 'supplier' => 'Second', 'source' => 'Invoice 2', 'lines' => [['productId' => '1', 'denom' => 0, 'cost' => '4000', 'rows' => [['pin' => 'another-pin', 'serial' => 'another-serial'], ['pin' => '', 'serial' => 'bad']]]]]);
        $r = app(StockWorkflow::class)->submit(['requestId' => 'submit-fixture', 'previewId' => $p['previewId'], 'previewHash' => $p['previewHash'], 'excludeRejected' => true]);
        app(StockWorkflow::class)->review(['requestId' => 'review-fixture', 'id' => $r['id'], 'version' => 0, 'decision' => 'approve']);
        $report = app(InventoryReports::class)->read(['type' => 'procurement', 'filters' => ['supplier' => 'Second'], 'all' => true]);
        $this->assertCount(1, $report['rows']);
        $this->assertSame(2, $report['totals']['uploaded']);
        $this->assertSame(1, $report['totals']['accepted']);
        $this->assertSame(1, $report['totals']['rejected']);
        $this->assertSame(1, $report['totals']['added']);
    }

    public function test_report_financial_totals_exclude_unpaid_orders_and_review_receipts(): void
    {
        $this->stock();
        $store = app(Store::class);
        $s = $store->read();
        foreach (['delivered', 'delivered', 'reserved', 'cancelled'] as $i => $state) {
            $s['orders'][] = ['id' => 'report-'.$i, 'reference' => 'report-'.$i, 'customerId' => $s['customers'][0]['id'], 'customer' => 'Fixture', 'productId' => 1, 'name' => 'Fixture', 'value' => 5000, 'quantity' => 1, 'price' => 5250, 'date' => '2026-11-14', 'createdAt' => '2026-11-14T10:00:0'.$i.'+03:00', 'costTotal' => $i === 0 ? '4500.001' : null, 'profitBeforeFees' => $i === 0 ? '749.999' : null];
        }
        $store->save($s);
        foreach (['delivered', 'delivered', 'reserved', 'cancelled'] as $i => $state) {
            DB::table('order_fulfillments')->insert(['order_id' => 'report-'.$i, 'state' => $state, 'payment_status' => $state === 'delivered' ? 'confirmed' : 'pending', 'provider' => 'Qi', 'amount' => '5250', 'quantity' => 1, 'expires' => time()]);
        }
        foreach ([['delivered', null, 'IQD'], ['processing', null, 'IQD'], ['review', 'amount_mismatch', 'IQD'], ['review', 'currency_mismatch', 'USD']] as $i => [$outcome, $reason, $currency]) {
            DB::table('payment_receipts')->insert(['id' => 'receipt-'.$i, 'order_id' => 'report-'.$i, 'provider' => 'Qi', 'transaction_id' => 'transaction-'.$i, 'event_id' => 'event-'.$i, 'amount' => '5250', 'currency' => $currency, 'outcome' => $outcome, 'reason' => $reason, 'received_at' => time() + $i]);
        }
        $reports = app(InventoryReports::class);
        $sales = $reports->read(['type' => 'sales']);
        $this->assertSame('10500.000', $sales['totals']['deliveredAmount']);
        $this->assertSame('4500.001', $sales['totals']['knownDeliveredCost']);
        $this->assertSame('749.999', $sales['totals']['deliveredGrossProfit']);
        $this->assertSame(1, $sales['totals']['deliveredUnknownCost']);
        $this->assertSame('report-3', $sales['rows'][0]['id']);
        $this->assertSame(1, $reports->read(['type' => 'sales', 'page' => 999])['page']);
        $payments = $reports->read(['type' => 'payments']);
        $this->assertSame('10500.000', $payments['totals']['confirmedAmount']);
        $this->assertSame('USD', $payments['rows'][0]['currency']);
        $this->assertCount(2, $reports->read(['type' => 'payments', 'filters' => ['status' => 'review']])['rows']);
        $this->assertSame($payments['totals'], $reports->read(['type' => 'payments', 'all' => true])['totals']);
    }

    private function xlsx(string $cell): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fatya-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>PIN</t></is></c></row><row r="7">'.$cell.'</row></sheetData></worksheet>');
        $zip->addFromString('xl/styles.xml', '<styleSheet><numFmts><numFmt numFmtId="164" formatCode="0000000000000000"/></numFmts><cellXfs><xf numFmtId="0"/><xf numFmtId="164"/></cellXfs></styleSheet>');
        $zip->close();

        return new UploadedFile($path, 'fixture.xlsx', null, null, true);
    }

    public function test_reader_preserves_long_text_zero_mask_and_original_row(): void
    {
        foreach (['<c r="A7" t="inlineStr"><is><t>0000000000000001</t></is></c>', '<c r="A7" s="1"><v>1</v></c>'] as $cell) {
            $file = $this->xlsx($cell);
            try {
                $s = app(ImportFileReader::class)->read($file);
                $this->assertSame('0000000000000001', $s[0]['rows'][1][0]);
                $this->assertSame([1, 7], $s[0]['sourceRows']);
            } finally {
                unlink($file->getPathname());
            }
        }
    }

    public function test_reader_rejects_formula_and_long_numeric_cells(): void
    {
        foreach (['<c r="A7"><f>1+1</f><v>2</v></c>', '<c r="A7"><v>1234567890123456</v></c>'] as $cell) {
            $file = $this->xlsx($cell);
            try {
                app(ImportFileReader::class)->read($file);
                $this->fail();
            } catch (ApiError $e) {
                $this->assertSame(422, $e->status);
            } finally {
                unlink($file->getPathname());
            }
        }
    }

    public function test_text_reader_keeps_physical_line_numbers_and_rejects_open_quote(): void
    {
        $file = UploadedFile::fake()->createWithContent('fixture.csv', "Serial,PIN\n\n00001,0000000000000001\n");
        $s = app(ImportFileReader::class)->read($file);
        $this->assertSame([1, 3], $s[0]['sourceRows']);
        $this->assertSame('0000000000000001', $s[0]['rows'][1][1]);
        $this->expectException(ApiError::class);
        app(ImportFileReader::class)->delimited('"unclosed');
    }

    public function test_same_alias_is_scoped_by_manufacturer_and_ambiguity_is_not_guessed(): void
    {
        $this->stock();
        $store = app(Store::class);
        $s = $store->read();
        $s['products'][0]['manufacturerId'] = 'm1';
        $s['products'][] = [...$s['products'][0], 'id' => 2, 'manufacturerId' => 'm2'];
        $store->save($s);
        DB::table('stock_category_codes')->insert([['scope' => 'manufacturer:m1', 'code' => 'E5K', 'product_id' => '1', 'denomination' => 5000], ['scope' => 'manufacturer:m2', 'code' => 'E5K', 'product_id' => '2', 'denomination' => 5000]]);
        $service = app(ImportCategories::class);
        $this->assertSame('1', $service->resolve(['categoryCode' => "\u{200F}EVS-E5K", 'manufacturerId' => 'm1'], $s)['productId']);
        $this->assertSame('2', $service->resolve(['categoryCode' => 'EVD-E5K', 'manufacturerId' => 'm2'], $s)['productId']);
        $this->assertSame('EVD1', ImportCategories::code('EVD1'));
        $this->expectException(ApiError::class);
        $service->resolve(['categoryCode' => 'E5K'], $s);
    }

    public function test_catalogs_retain_province_identity_and_remote_service_type(): void
    {
        $this->stock();
        config(['digital.rabiaa.enabled' => true, 'digital.rabiaa.key' => 'fixture-provider-key', 'digital.rabiaa.owner_city' => 'Fixture1']);
        Http::fake(['*/vendor/provinces' => Http::response(['data' => [['id' => 1, 'title' => 'Fixture1'], ['id' => 2, 'title' => 'Fixture2']]]), '*/catalog*' => Http::response(['data' => [['catalogId' => 9, 'title' => 'Fixture', 'publicPrice' => 4500, 'vendorPrice' => 0, 'inStock' => true, 'packageType' => 'standard']]])]);
        $p = app(DigitalProviders::class);
        $a = $p->catalog('rabiaa', '1');
        config(['digital.rabiaa.owner_city' => 'Fixture2']);
        $b = $p->catalog('rabiaa', '2');
        $this->assertSame('1', json_decode($p->catalogRecord('rabiaa', '1')->snapshot, true)['provinceId']);
        $this->assertSame('2', json_decode($p->catalogRecord('rabiaa', '2')->snapshot, true)['provinceId']);
        $this->assertNotSame($a['hash'], $b['hash']);
        config(['digital.topup.enabled' => true, 'digital.topup.key' => 'fixture-provider-key']);
        Http::fake(['*/products' => Http::response(['status' => 'ok', 'products' => [['id' => 7, 'title' => 'A', 'price' => 1, 'show2site' => 1, 'type' => 'topup'], ['id' => 7, 'title' => 'B', 'price' => 2, 'show2site' => 1, 'type' => 'bundle']]])]);
        $c = $p->catalog('topup');
        $s = app(Store::class)->read();
        $p->saveOffer(['revision' => $s['revision'], 'productId' => '1', 'denom' => 0, 'retail' => '5250', 'active' => true, 'provider' => 'topup', 'providerId' => '7', 'type' => 'bundle', 'catalogHash' => $c['hash']]);
        $this->assertSame('bundle', json_decode(DB::table('digital_offers')->value('snapshot'), true)['type']);
        $this->expectException(ApiError::class);
        $p->saveOffer(['revision' => app(Store::class)->read()['revision'], 'productId' => '1', 'denom' => 0, 'retail' => '5250', 'active' => true, 'provider' => 'topup', 'providerId' => '7', 'catalogHash' => $c['hash']]);
    }

    public function test_movement_report_uses_approval_time_not_withdrawal_request_time(): void
    {
        $this->stock();
        DB::table('inventory_cards')->update(['imported_at' => strtotime('2030-01-01 UTC')]);
        $service = app(InventoryExports::class);
        $r = $service->create($this->request('withdrawal'));
        $service->review(['id' => $r['id'], 'version' => 0, 'decision' => 'approve', 'password' => self::PASSWORD]);
        DB::table('inventory_exports')->where('id', $r['id'])->update(['created' => strtotime('2030-01-01 UTC'), 'approved_at' => strtotime('2030-01-02 UTC')]);
        $report = app(InventoryReports::class)->read(['type' => 'movements', 'filters' => ['from' => '2030-01-02', 'to' => '2030-01-02'], 'all' => true]);
        $this->assertSame(3, $report['totals']['opening']);
        $this->assertSame(0, $report['totals']['incoming']);
        $this->assertSame(1, $report['totals']['withdrawn']);
        $this->assertSame(2, $report['totals']['ending']);
    }

    public function test_reader_rejects_supplier_count_mismatch_and_mixed_category_sheet(): void
    {
        foreach (["SAMPLE,3,EVS-E5K,5000,IQD\nS,P,2030-01-01", "Serial,PIN,CategoryCode\nS1,P1,E5K\nS2,P2,EV5"] as $text) {
            $file = UploadedFile::fake()->createWithContent('fixture.csv', $text);
            try {
                app(ImportFileReader::class)->read($file);
                $this->fail('Invalid declared count/category mix accepted');
            } catch (ApiError $e) {
                $this->assertSame(422, $e->status);
            }
        }
    }
}
