<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\CardFields;
use App\Services\ImportCategories;
use App\Services\Inventory;
use App\Services\OrderFulfillment;
use App\Services\ProductFields;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use App\Services\Store;
use App\Services\VerifiedPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProductFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function fixture(bool $required = false): array
    {
        Http::preventStrayRequests();
        $s = app(Store::class)->read();
        $s['categories'] = [['id' => 'c', 'name' => 'Cards', 'en' => 'Cards', 'icon' => 'Layers', 'active' => true]];
        $s['products'] = [['id' => 1, 'name' => 'بطاقة عربية', 'en' => 'Fixture', 'tag' => 'وصف عربي', 'color' => '#034037', 'image' => 'cards/ar.png', 'category' => 'Cards', 'active' => true, 'unit' => 'IQD', 'values' => [5000, 10000], 'prices' => [5250, 10400], 'stock' => [0, 0], 'denomImages' => ['cards/ar5.png', ''], 'importFields' => ['pin' => true, 'expiry' => true, 'serial' => $required, 'cvc' => $required, 'reference' => $required]]];
        $s['customers'] = [Accounts::newCustomer(['name' => 'Fixture', 'email' => ''], '07700000000')];
        app(Store::class)->save($s);

        return $s;
    }

    private function admin(): void
    {
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);
    }

    private function input(array $s, array $rows): array
    {
        return ['revision' => $s['revision'], 'supplier' => 'Fixture', 'source' => 'Fixture invoice', 'lines' => [['productId' => '1', 'denom' => 0, 'cost' => '4500.000', 'rows' => $rows]]];
    }

    private function approve(array $p, string $key = 'product-fields-approve'): array
    {
        return app(StockOrders::class)->approve(['previewId' => $p['previewId'], 'previewHash' => $p['previewHash'], 'requestId' => $key, 'excludeRejected' => false]);
    }

    public function test_translations_aliases_and_order_persist_and_private_rules_stay_admin_only(): void
    {
        $s = $this->fixture();
        $this->admin();
        $p = $s['products'][0];
        $p['displayOrder'] = 3;
        $p['denomNames'] = ['خمسة آلاف', 'عشرة آلاف'];
        $p['denomDescriptions'] = ['وصف الفئة', ''];
        $p['denomOrder'] = [20, 10];
        $p['denomImportCodes'] = ['EVS-new1, 00125, فئة1', ''];
        $p['translations'] = ['ar' => ['name' => $p['name'], 'description' => $p['tag'], 'image' => 'cards/ar.png'], 'ku' => ['name' => 'کوردی', 'description' => 'وەسف', 'image' => 'cards/ku.png', 'denomNames' => ['ناوی پۆل'], 'denomDescriptions' => ['وردەکاری'], 'denomImages' => ['cards/ku5.png']], 'en' => ['name' => 'English card', 'description' => 'English description', 'image' => 'cards/en.png', 'denomNames' => ['Five thousand'], 'denomImages' => ['cards/en5.png']]];
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertOk();
        $saved = app(Store::class)->read()['products'][0];
        $this->assertSame('English card', $saved['translations']['en']['name']);
        $this->assertSame('cards/ku5.png', $saved['translations']['ku']['denomImages'][0]);
        $this->assertSame('خمسة آلاف', $saved['denomNames'][0]);
        $this->assertSame([20, 10], $saved['denomOrder']);
        $this->assertDatabaseHas('stock_category_codes', ['code' => 'NEW1', 'product_id' => '1']);
        $this->assertDatabaseHas('stock_category_codes', ['code' => '00125']);
        $this->assertDatabaseHas('stock_category_codes', ['code' => 'فئة1']);
        $this->getJson('/api/admin/state')->assertOk()->assertJsonPath('products.0.importFields.pin', true);
        $public = $this->getJson('/api/state')->assertOk()->json();
        $this->assertArrayNotHasKey('importFields', $public['products'][0]);
        $this->assertArrayNotHasKey('denomImportCodes', $public['products'][0]);
        $this->assertSame('cards/en.png', $public['products'][0]['translations']['en']['image']);
        $this->assertSame(0, app(ImportCategories::class)->resolve(['categoryCode' => '00125'], app(Store::class)->read())['denom']);
    }

    public function test_empty_identifiers_are_optional_and_single_mapping_is_safe_to_retry(): void
    {
        $s = $this->fixture();
        $this->admin();
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $s['products'][0]])->assertOk();
        $this->assertDatabaseCount('stock_category_codes', 0);
        $s = app(Store::class)->read();
        $body = ['revision' => $s['revision'], 'code' => 'FIVE', 'productId' => '1', 'denom' => 0];
        $first = app(ImportCategories::class)->add($body);
        $second = app(ImportCategories::class)->add([...$body, 'revision' => $first['revision']]);
        $this->assertSame($first['revision'], $second['revision']);
        $this->assertDatabaseCount('stock_category_codes', 1);
        $admin = $this->getJson('/api/admin/state')->json();
        $this->assertSame('FIVE', $admin['products'][0]['denomImportCodes'][0]);
        $this->expectException(ApiError::class);
        app(ImportCategories::class)->add([...$body, 'revision' => $second['revision'], 'denom' => 1]);
    }

    public function test_alias_conflict_rolls_back_product_and_revision(): void
    {
        $s = $this->fixture();
        $s['products'][] = [...$s['products'][0], 'id' => 2, 'name' => 'Other'];
        app(Store::class)->save($s);
        DB::table('stock_category_codes')->insert(['code' => 'TAKEN', 'product_id' => '2', 'denomination' => 5000]);
        $this->admin();
        $p = [...$s['products'][0], 'name' => 'Changed', 'denomImportCodes' => ['TAKEN', '']];
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertStatus(409);
        $current = app(Store::class)->read();
        $this->assertSame($s['revision'], $current['revision']);
        $this->assertSame('بطاقة عربية', $current['products'][0]['name']);
    }

    public function test_invalid_pin_policy_and_translated_image_are_rejected(): void
    {
        $s = $this->fixture();
        $this->admin();
        $p = $s['products'][0];
        $p['importFields']['pin'] = false;
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertStatus(422);
        $p = $s['products'][0];
        $p['translations'] = ['ku' => ['image' => 'javascript:alert(1)']];
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertStatus(400);
    }

    public function test_old_client_can_rename_arabic_without_stale_translation_overriding_it(): void
    {
        $s = $this->fixture();
        $this->admin();
        $p = $s['products'][0];
        $p['translations'] = ['ar' => ['name' => $p['name'], 'image' => $p['image']]];
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertOk();
        $s = app(Store::class)->read();
        $p = $s['products'][0];
        $p['name'] = 'اسم جديد';
        $p['image'] = 'cards/new.png';
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertOk();
        $saved = app(Store::class)->read()['products'][0];
        $this->assertSame('اسم جديد', $saved['translations']['ar']['name']);
        $this->assertSame('cards/new.png', $saved['translations']['ar']['image']);
    }

    public function test_required_fields_are_enforced_in_both_import_paths_and_expiry_is_optional(): void
    {
        $s = $this->fixture(true);
        $service = app(StockOrders::class);
        $bad = $service->preview($this->input($s, [['pin' => '00001', 'serial' => 'S1', 'cvc' => '007']]));
        $this->assertSame(0, $bad['quantity']);
        $this->assertStringContainsString('الرقم المرجعي', $bad['errors'][0]['reason']);
        $legacy = app(Inventory::class)->import($s, ['name' => 'test.xlsx', 'rows' => [['product_id' => 1, 'denomination' => 5000, 'code' => '00002', 'serial' => 'S2', 'cvc' => '007']]]);
        $this->assertSame(0, $legacy['accepted']);
        $good = $service->preview($this->input($s, [['pin' => '00003', 'serial' => 'S3', 'cvc' => '007', 'reference' => 'REF-000001']]));
        $this->assertSame(1, $good['quantity']);
        $this->approve($good);
        $row = DB::table('inventory_cards')->first();
        $this->assertNull($row->expires_on);
        $this->assertSame(['cvc' => '007', 'reference' => 'REF-000001'], CardFields::details($row));
        $this->assertStringNotContainsString('REF-000001', json_encode($row));
    }

    public function test_extra_details_remain_private_until_verified_payment_and_owner_delivery(): void
    {
        $s = $this->fixture(true);
        $p = app(StockOrders::class)->preview($this->input($s, [['pin' => '00003', 'serial' => 'S3', 'cvc' => '000', 'reference' => 'REF-000009', 'expiry' => now()->addYear()->toDateString()]]));
        $this->approve($p);
        $service = app(OrderFulfillment::class);
        $order = $service->reserve($s['customers'][0], ['productId' => 1, 'denom' => 0, 'quantity' => 1, 'payment' => 'Qi', 'reference' => 'metadata-order-01'])['order'];
        try {
            $service->codes($order['id'], $s['customers'][0]);
            $this->fail('Unpaid codes');
        } catch (ApiError $e) {
            $this->assertSame(409, $e->status);
        }
        $service->confirm(new VerifiedPayment($order['id'], 'Qi', 'metadata-payment-01', 'metadata-event-01', '5250.000', 'IQD'));
        $codes = $service->codes($order['id'], $s['customers'][0])['codes'];
        $this->assertSame('000', $codes[0]['cvc']);
        $this->assertSame('REF-000009', $codes[0]['reference']);
        $this->assertTrue($codes[0]['detailed']);
        try {
            $service->codes($order['id'], ['id' => 'unrelated']);
            $this->fail('Other customer codes');
        } catch (ApiError $e) {
            $this->assertSame(404, $e->status);
        }
        $this->assertStringNotContainsString('REF-000009', $this->getJson('/api/state')->getContent());
        Http::assertNothingSent();
    }

    public function test_review_preserves_extra_details_and_checks_new_requirements(): void
    {
        $s = $this->fixture(true);
        $workflow = app(StockWorkflow::class);
        $workflow->savePolicy(['version' => 0, 'required' => true]);
        $s = app(Store::class)->read();
        $p = app(StockOrders::class)->preview($this->input($s, [['pin' => '00044', 'serial' => 'S44', 'cvc' => '009', 'reference' => 'REF-0044']]));
        $submission = app(StockOrders::class)->approve(['previewId' => $p['previewId'], 'previewHash' => $p['previewHash'], 'requestId' => 'metadata-review-01', 'excludeRejected' => false]);
        $workflow->review(['id' => $submission['id'], 'version' => $submission['version'], 'decision' => 'approve', 'reason' => '', 'requestId' => 'metadata-review-confirm']);
        $this->assertSame('009', CardFields::details(DB::table('inventory_cards')->first())['cvc']);
    }

    public function test_category_translations_and_canonical_names_stay_compatible(): void
    {
        $s = $this->fixture();
        $this->admin();
        $category = [...$s['categories'][0], 'image' => 'cards/category-ar.png', 'translations' => ['ar' => ['name' => 'Wrong duplicate source', 'description' => 'وصف عربي'], 'ku' => ['name' => 'پۆل', 'image' => 'cards/category-ku.png'], 'en' => ['name' => 'Category']]];
        $this->putJson('/api/admin/categories', ['revision' => $s['revision'], 'data' => $category])->assertOk();
        $saved = app(Store::class)->read()['categories'][0];
        $this->assertSame('Cards', $saved['translations']['ar']['name']);
        $this->assertSame('cards/category-ku.png', $saved['translations']['ku']['image']);
        $this->assertSame('وصف عربي', $saved['translations']['ar']['description']);
        $products = ProductFields::sort([['id' => 1, 'displayOrder' => 50], ['id' => 2, 'displayOrder' => 3]]);
        $this->assertSame([2, 1], array_column($products, 'id'));
    }

    public function test_denomination_policy_override_is_enforced_in_server_preview(): void
    {
        $s = $this->fixture();
        $s['products'][0]['denomImportFields'] = [null, ['pin' => true, 'cvc' => true]];
        app(Store::class)->save($s);
        $p = app(StockOrders::class)->preview(['revision' => $s['revision'], 'supplier' => 'Fixture', 'source' => 'Fixture', 'lines' => [
            ['productId' => '1', 'denom' => 0, 'cost' => '4500', 'rows' => [['pin' => 'policy-first']]],
            ['productId' => '1', 'denom' => 1, 'cost' => '9000', 'rows' => [['pin' => 'policy-second']]],
        ]]);
        $this->assertSame(1, $p['quantity']);
        $this->assertSame(1, $p['rejected']);
        $this->assertSame(2, $p['errors'][0]['line']);
    }

    public function test_legacy_denomination_removal_preserves_translation_and_identifier_by_value(): void
    {
        $s = $this->fixture();
        $this->admin();
        $p = $s['products'][0];
        $p['denomNames'] = ['خمسة', 'عشرة'];
        $p['denomDescriptions'] = ['وصف خمسة', 'وصف عشرة'];
        $p['denomImportCodes'] = ['FIVE', 'TEN'];
        $p['denomOrder'] = [20, 10];
        $p['translations'] = ['en' => ['name' => 'Fixture', 'denomNames' => ['Five', 'Ten'], 'denomDescriptions' => ['Description five', 'Description ten'], 'denomImages' => ['cards/five.png', 'cards/ten.png']]];
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertOk();
        $s = app(Store::class)->read();
        $p = $s['products'][0];
        $p['values'] = [10000];
        $p['prices'] = [10400];
        $p['stock'] = [0];
        $p['denomImages'] = [''];
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => $p])->assertOk();
        $saved = app(Store::class)->read()['products'][0];
        $this->assertSame(['عشرة'], $saved['denomNames']);
        $this->assertSame(['Ten'], $saved['translations']['en']['denomNames']);
        $this->assertSame(['cards/ten.png'], $saved['translations']['en']['denomImages']);
        $this->assertSame(['TEN'], app(ImportCategories::class)->forProduct($saved));
    }
}
