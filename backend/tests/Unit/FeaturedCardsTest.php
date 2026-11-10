<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\AdminPolicy;
use App\Services\CatalogEditor;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FeaturedCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    private function config(): array
    {
        return ['visible' => false, 'webVisible' => true, 'interval' => 5200, 'productIds' => [2, 1], 'label' => 'مختارات', 'title' => 'عنوان', 'accent' => 'مميز', 'backgroundStart' => '#123456', 'backgroundEnd' => '#654321', 'backgroundImage' => 'cards/background.webp'];
    }

    public function test_every_field_round_trips_through_admin_and_application_api_after_reload(): void
    {
        $store = app(Store::class);
        $s = $store->read();
        $s['categories'] = [['id' => 'cards', 'name' => 'Cards', 'en' => 'Cards', 'icon' => 'Layers', 'active' => true]];
        $product = ['id' => 1, 'name' => 'Card', 'en' => 'Card', 'tag' => '', 'color' => '#034037', 'image' => '', 'category' => 'Cards', 'active' => true, 'unit' => 'IQD', 'values' => [5000], 'prices' => [5200], 'stock' => [0]];
        $s['products'] = [$product, [...$product, 'id' => 2, 'name' => 'Second card']];
        $store->save($s);
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue())->withHeaders(['X-Fatya-Request' => '1']);
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
        foreach ([
            [...$this->config(), 'visible' => true, 'webVisible' => false, 'interval' => 1400, 'backgroundImage' => $image],
            [...$this->config(), 'visible' => false, 'interval' => 0, 'productIds' => [1, 2], 'backgroundImage' => ''],
        ] as $config) {
            $revision = $store->read()['revision'];
            $this->putJson('/api/admin/featuredCards', ['revision' => $revision, 'data' => $config])->assertOk()->assertJsonPath('settings.featuredCards', $config);
            $this->getJson('/api/admin/state')->assertOk()->assertJsonPath('settings.featuredCards', $config);
            $this->getJson('/api/state')->assertOk()->assertJsonPath('settings.featuredCards', $config);
            $this->assertSame($config, app(Store::class)->read()['settings']['featuredCards']);
            $this->putJson('/api/admin/featuredCards', ['revision' => $revision, 'data' => [...$config, 'title' => 'Stale edit']])->assertConflict();
            $this->assertSame($config, $store->read()['settings']['featuredCards']);
        }
        $saved = $store->read();
        $this->putJson('/api/admin/settings', ['revision' => $saved['revision'], 'data' => [...$saved['settings'], 'name' => 'General settings edit']])->assertOk();
        $this->getJson('/api/state')->assertOk()->assertJsonPath('settings.featuredCards', $config);
    }

    public function test_settings_persist_in_order_and_survive_other_settings_saves(): void
    {
        $store = app(Store::class);
        $s = $store->read();
        $s['products'] = [['id' => 1], ['id' => 2]];
        $editor = app(CatalogEditor::class);
        $editor->update($s, 'featuredCards', $this->config());
        $store->save($s);
        $this->assertSame($this->config(), $store->read()['settings']['featuredCards']);
        $editor->update($s, 'settings', [...$s['settings'], 'name' => 'New']);
        $store->save($s);
        $this->assertSame($this->config(), $store->read()['settings']['featuredCards']);
        $editor->update($s, 'featuredCards', [...$this->config(), 'productIds' => [], 'interval' => 0]);
        $this->assertSame([], $s['settings']['featuredCards']['productIds']);
        $this->assertSame(0, $s['settings']['featuredCards']['interval']);
    }

    public function test_older_clients_preserve_the_independent_website_visibility(): void
    {
        $s = app(Store::class)->read();
        $s['products'] = [['id' => 1], ['id' => 2]];
        $editor = app(CatalogEditor::class);
        $editor->update($s, 'featuredCards', [...$this->config(), 'visible' => true, 'webVisible' => false]);
        $oldClient = $this->config();
        unset($oldClient['webVisible']);
        $editor->update($s, 'featuredCards', $oldClient);
        $this->assertFalse($s['settings']['featuredCards']['webVisible']);
        $this->assertFalse($s['settings']['featuredCards']['visible']);
    }

    public function test_invalid_inputs_are_rejected_without_mutation(): void
    {
        $s = app(Store::class)->read();
        $s['products'] = [['id' => 1], ['id' => 2]];
        foreach ([['productIds' => [1, 1]], ['productIds' => [999]], ['productIds' => ['1']], ['interval' => 999], ['interval' => 30001], ['visible' => 'false'], ['webVisible' => 'false'], ['backgroundStart' => 'url(evil)'], ['backgroundImage' => 'javascript:alert(1)'], ['title' => str_repeat('x', 121)]] as $invalid) {
            $before = $s;
            try {
                app(CatalogEditor::class)->update($s, 'featuredCards', [...$this->config(), ...$invalid]);
                $this->fail('Invalid featured settings accepted');
            } catch (ApiError $e) {
                $this->assertSame($before, $s);
            }
        }
    }

    public function test_dedicated_permission_required_and_guest_cannot_save(): void
    {
        $this->withHeaders(['X-Fatya-Request' => '1'])->putJson('/api/admin/featuredCards', ['revision' => 0, 'data' => $this->config()])->assertUnauthorized();
        foreach ([[], ['featured_cards.view'], ['settings.identity'], ['web_slides.update']] as $permissions) {
            $request = Request::create('/api/admin/featuredCards', 'PUT');
            $request->attributes->set('adminAccess', ['owner' => false, 'permissions' => $permissions]);
            $this->app->instance('request', $request);
            try {
                app(AdminPolicy::class)->authorize($request);
                $this->fail('Unrelated permissions accepted');
            } catch (ApiError $e) {
                $this->assertStringContainsString('صلاحية', $e->getMessage());
            }
        }
        $request->attributes->set('adminAccess', ['owner' => false, 'permissions' => ['featured_cards.update']]);
        app(AdminPolicy::class)->authorize($request);
        app(AdminPolicy::class)->collection('featuredCards', $this->config(), []);
        $this->assertTrue(true);
    }
}
