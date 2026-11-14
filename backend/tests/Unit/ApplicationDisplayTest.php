<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\ApplicationDisplay;
use App\Services\CatalogEditor;
use App\Services\CompanyHierarchy;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApplicationDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    public function test_display_uses_existing_ids_and_survives_an_unrelated_settings_save(): void
    {
        $store = app(Store::class);
        $state = $store->read();
        $state['categories'] = [['id' => 'category-one', 'name' => 'Fixture', 'active' => true]];
        $state['settings']['name'] = 'Fixture';
        $store->save($state);
        $before = $state;
        $settings = ['default' => ['mode' => 'grid', 'columns' => 2, 'flatCompanies' => false], 'categories' => ['category-one' => ['mode' => 'row', 'columns' => 3, 'flatCompanies' => false]]];
        app(ApplicationDisplay::class)->save(['revision' => $state['revision'], 'settings' => $settings]);
        $state = $store->read();
        $this->assertSame($settings, $state['settings']['catalogDisplay']);
        $this->assertSame($before['categories'], $state['categories']);
        $this->assertSame($before['products'], $state['products']);
        app(CatalogEditor::class)->update($state, 'settings', [...$state['settings'], 'aboutText' => 'Changed description']);
        $this->assertSame($settings, $state['settings']['catalogDisplay']);
        $this->expectException(ApiError::class);
        app(ApplicationDisplay::class)->save(['revision' => $before['revision'], 'settings' => $settings]);
    }

    public function test_preview_uses_the_public_catalog_without_supplier_contacts_or_import_metadata(): void
    {
        Http::preventStrayRequests();
        $state = app(Store::class)->read();
        $state['categories'] = [['id' => 'cards', 'name' => 'Cards', 'active' => true]];
        $state['companies'] = [['id' => 'root', 'name' => 'Fixture company', 'role' => 'manufacturer', 'active' => true, 'phone' => 'private-phone', 'address' => 'private-address']];
        $state['products'] = [['id' => 1, 'name' => 'Fixture', 'category' => 'Cards', 'manufacturerId' => 'root', 'active' => true, 'unit' => 'IQD', 'values' => [5000], 'prices' => [5250], 'stock' => [0], 'denomImportCodes' => ['E5K'], 'importFields' => ['pin' => true]]];
        app(Store::class)->save($state);
        $preview = app(ApplicationDisplay::class)->preview();
        $public = $this->getJson('/api/state')->assertOk()->json();
        $this->assertSame($public['products'], $preview['products']);
        $this->assertStringNotContainsString('private-phone', json_encode($preview));
        $this->assertArrayNotHasKey('denomImportCodes', $preview['products'][0]);
        Http::assertNothingSent();
    }

    public function test_company_arrows_move_only_siblings_and_preserve_other_data(): void
    {
        $store = app(Store::class);
        $state = $store->read();
        $state['companies'] = array_map(fn ($id, $parent, $order) => ['id' => $id, 'name' => $id, 'role' => 'manufacturer', 'active' => true, 'parentId' => $parent, 'displayOrder' => $order, 'image' => '', 'phone' => 'private-fixture', 'address' => 'private-address'], ['root', 'b1', 'b2', 'b3', 'other'], ['', 'root', 'root', 'root', ''], [0, 0, 1, 2, 1]);
        $store->save($state);
        $before = $state;
        $result = app(CompanyHierarchy::class)->move(['revision' => $state['revision'], 'id' => 'b3', 'direction' => -1]);
        $after = $store->read();
        $map = collect($after['companies'])->keyBy('id');
        $this->assertTrue($result['changed']);
        $this->assertSame(0, $map['b1']['displayOrder']);
        $this->assertSame(2, $map['b2']['displayOrder']);
        $this->assertSame(1, $map['b3']['displayOrder']);
        $this->assertSame(1, $map['other']['displayOrder']);
        foreach ($after['companies'] as $company) {
            $original = collect($before['companies'])->firstWhere('id', $company['id']);
            unset($company['displayOrder'], $original['displayOrder']);
            $this->assertSame($original, $company);
        }
        $this->assertSame($before['orders'], $after['orders']);
    }
}
