<?php

namespace Tests\Unit;

use App\Services\Accounts;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogManagementTest extends TestCase
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
        $s['categories'] = [['id' => 'cards', 'name' => 'Cards', 'en' => 'Cards', 'icon' => 'Layers', 'active' => true]];
        $s['companies'] = [
            ['id' => 'maker', 'name' => 'Maker', 'role' => 'manufacturer', 'active' => true],
            ['id' => 'source', 'name' => 'Original source', 'role' => 'supplier', 'active' => true, 'phone' => '07701234567', 'address' => 'Source address'],
        ];
        $p = ['id' => 1, 'name' => 'Card', 'en' => 'Card', 'category' => 'Cards', 'tag' => '', 'color' => '#034037', 'unit' => 'IQD', 'image' => '', 'active' => true, 'manufacturerId' => 'maker', 'values' => [5000], 'prices' => [5250], 'stock' => [0], 'displayOrder' => 30];
        $s['products'] = [$p, [...$p, 'id' => 2, 'displayOrder' => 10], [...$p, 'id' => 3, 'displayOrder' => 20]];
        app(Store::class)->save($s);
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);

        return $s;
    }

    public function test_arrows_save_one_atomic_order_and_public_application_reads_it(): void
    {
        $s = $this->fixture();
        $this->postJson('/api/admin/products/reorder', ['revision' => $s['revision'], 'id' => 1, 'direction' => -1])->assertOk();
        $saved = app(Store::class)->read();
        $this->assertSame([2, 1, 3], array_column($saved['products'], 'id'));
        $this->assertSame([10, 20, 30], array_column($saved['products'], 'displayOrder'));
        $this->assertSame([[5250], [5250], [5250]], array_column($saved['products'], 'prices'));
        $this->getJson('/api/state')->assertOk()->assertJsonPath('products.0.id', 2)->assertJsonPath('products.1.id', 1)->assertJsonPath('products.2.id', 3);
        $this->postJson('/api/admin/products/reorder', ['revision' => $s['revision'], 'id' => 1, 'direction' => 1])->assertConflict();
        $this->assertSame([2, 1, 3], array_column(app(Store::class)->read()['products'], 'id'));
        $this->postJson('/api/admin/products/reorder', ['revision' => $saved['revision'], 'id' => 2, 'direction' => -1])->assertOk();
        $this->assertSame($saved['revision'], app(Store::class)->read()['revision']);
    }

    public function test_denomination_arrows_persist_independently_without_moving_inventory_indices(): void
    {
        $s = $this->fixture();
        $s['products'][0] = [...$s['products'][0], 'values' => [5000, 10000, 25000], 'prices' => [5250, 10400, 25800], 'stock' => [0, 0, 0], 'denomOrder' => [30, 10, 20], 'denomSupplierIds' => ['source', '', 'source']];
        app(Store::class)->save($s);
        $original = $s['products'];
        $this->postJson('/api/admin/products/reorder', ['revision' => $s['revision'], 'id' => 1, 'denom' => 0, 'direction' => -1])->assertOk();
        $saved = app(Store::class)->read();
        $this->assertSame([20, 10, 30], $saved['products'][0]['denomOrder']);
        $expected = $original;
        $expected[0]['denomOrder'] = [20, 10, 30];
        $this->assertSame($expected, $saved['products']);
        $this->getJson('/api/admin/state')->assertOk()->assertJsonPath('products.0.denomOrder', [20, 10, 30]);
        $public = $this->getJson('/api/state')->assertOk()->json('products');
        $this->assertSame([20, 10, 30], collect($public)->firstWhere('id', 1)['denomOrder']);
        $this->postJson('/api/admin/products/reorder', ['revision' => $s['revision'], 'id' => 1, 'denom' => 0, 'direction' => 1])->assertConflict();
        foreach ([[1, -1], [2, 1]] as [$denom, $direction]) {
            $this->postJson('/api/admin/products/reorder', ['revision' => $saved['revision'], 'id' => 1, 'denom' => $denom, 'direction' => $direction])->assertOk();
        }
        $this->assertSame($saved['revision'], app(Store::class)->read()['revision']);
        $this->postJson('/api/admin/products/reorder', ['revision' => $saved['revision'], 'id' => 1, 'denom' => -1, 'direction' => 1])->assertNotFound();
        $this->postJson('/api/admin/products/reorder', ['revision' => $saved['revision'], 'id' => 1, 'denom' => '0', 'direction' => 1])->assertStatus(422);
        $this->postJson('/api/admin/products/reorder', ['revision' => $saved['revision'], 'id' => 1, 'denom' => 0, 'direction' => 0])->assertStatus(422);
        $this->assertSame($expected, app(Store::class)->read()['products']);
    }

    public function test_source_contacts_and_company_and_denomination_links_persist_privately(): void
    {
        $s = $this->fixture();
        $source = ['name' => 'New source', 'role' => 'supplier', 'active' => true, 'phone' => '+9647701234567', 'address' => 'Private source address'];
        $this->putJson('/api/admin/companies', ['revision' => $s['revision'], 'data' => $source])->assertOk();
        $s = app(Store::class)->read();
        $new = collect($s['companies'])->firstWhere('name', 'New source');
        $this->assertSame($source['phone'], $new['phone']);
        $this->assertSame($source['address'], $new['address']);
        $this->putJson('/api/admin/companies', ['revision' => $s['revision'], 'data' => [...$s['companies'][0], 'sourceId' => $new['id']]])->assertOk();
        $s = app(Store::class)->read();
        $this->putJson('/api/admin/products', ['revision' => $s['revision'], 'data' => [...$s['products'][0], 'supplierCompanyId' => 'source', 'denomSupplierIds' => [$new['id']]]])->assertOk();
        $s = app(Store::class)->read();
        $this->assertSame($new['id'], $s['companies'][0]['sourceId']);
        $this->assertSame([$new['id']], $s['products'][0]['denomSupplierIds']);
        $response = $this->getJson('/api/state')->assertOk();
        $this->assertStringNotContainsString('Private source address', $response->getContent());
        $this->assertStringNotContainsString('+9647701234567', $response->getContent());
        $this->getJson('/api/admin/procurement/stock-orders')->assertOk()->assertJsonPath('sources.0.id', 'source')->assertJsonPath('sources.0.phone', '07701234567');
        $this->putJson('/api/admin/companies', ['revision' => $s['revision'], 'data' => [...$new, 'role' => 'manufacturer']])->assertStatus(400);
    }

    public function test_procurement_resolves_the_source_and_new_denomination_from_ids(): void
    {
        $s = $this->fixture();
        $body = ['revision' => $s['revision'], 'supplierId' => 'source', 'supplier' => 'Spoofed source name', 'source' => 'Invoice 001', 'lines' => [['productId' => '1', 'manufacturerId' => 'maker', 'denom' => -1, 'newValue' => '10000', 'newName' => 'New denomination', 'supplierCompanyId' => 'source', 'cost' => '9000', 'retail' => '11000', 'rows' => [['pin' => '000111222333444']]]]];
        $preview = $this->postJson('/api/admin/procurement/stock-orders/preview', $body)->assertOk()->assertJsonPath('supplier', 'Original source')->assertJsonPath('supplierId', 'source')->assertJsonPath('supplierPhone', '07701234567')->assertJsonPath('supplierAddress', 'Source address')->json();
        $this->postJson('/api/admin/procurement/stock-orders/approve', ['previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'requestId' => 'catalog-source-test-001', 'excludeRejected' => false])->assertOk();
        $saved = app(Store::class)->read();
        $p = collect($saved['products'])->firstWhere('id', 1);
        $this->assertSame('source', $p['denomSupplierIds'][1]);
        $this->getJson('/api/admin/procurement/stock-orders')->assertOk()->assertJsonPath('batches.0.supplierId', 'source');
        $body['revision'] = $saved['revision'];
        $body['supplierId'] = 'maker';
        $this->postJson('/api/admin/procurement/stock-orders/preview', $body)->assertStatus(422);
    }

    public function test_invalid_source_contact_or_identifier_is_rejected_without_writing(): void
    {
        $s = $this->fixture();
        foreach ([[['phone' => '-------'], 422], [['address' => str_repeat('x', 301)], 400], [['sourceId' => 'missing'], 422]] as [$invalid, $status]) {
            $this->putJson('/api/admin/companies', ['revision' => $s['revision'], 'data' => [...$s['companies'][0], ...$invalid]])->assertStatus($status);
            $this->assertSame($s['revision'], app(Store::class)->read()['revision']);
        }
    }
}
