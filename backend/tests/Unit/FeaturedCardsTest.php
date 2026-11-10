<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
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
        return ['visible' => false, 'interval' => 5200, 'productIds' => [2, 1], 'label' => 'مختارات', 'title' => 'عنوان', 'accent' => 'مميز', 'backgroundStart' => '#123456', 'backgroundEnd' => '#654321', 'backgroundImage' => 'cards/background.webp'];
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

    public function test_invalid_inputs_are_rejected_without_mutation(): void
    {
        $s = app(Store::class)->read();
        $s['products'] = [['id' => 1], ['id' => 2]];
        foreach ([['productIds' => [1, 1]], ['productIds' => [999]], ['productIds' => ['1']], ['interval' => 999], ['interval' => 30001], ['visible' => 'false'], ['backgroundStart' => 'url(evil)'], ['backgroundImage' => 'javascript:alert(1)'], ['title' => str_repeat('x', 121)]] as $invalid) {
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
