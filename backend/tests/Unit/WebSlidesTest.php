<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\CatalogEditor;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebSlidesTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    public function test_web_images_persist_independently_and_survive_general_settings_saves(): void
    {
        $store = app(Store::class);
        $editor = app(CatalogEditor::class);
        $s = $store->read();
        $s['products'] = [['id' => 1, 'image' => 'cards/original.webp']];
        $store->save($s);
        $rows = [['id' => 'web-test', 'name' => 'Web image', 'src' => 'cards/custom.webp', 'productId' => 1]];
        $editor->update($s, 'webSlides', $rows);
        $store->save($s);
        $saved = $store->read();
        $this->assertSame($rows, $saved['settings']['webSlides']);
        $this->assertSame('cards/original.webp', $saved['products'][0]['image']);
        $this->assertSame([], $saved['slides']);
        $this->assertSame([], $saved['middleSlides']);
        $editor->update($saved, 'settings', [...$saved['settings'], 'name' => 'Updated']);
        $store->save($saved);
        $this->assertSame($rows, $store->read()['settings']['webSlides']);
        $editor->update($saved, 'webSlides', []);
        $store->save($saved);
        $this->assertSame([], $store->read()['settings']['webSlides']);
    }

    public function test_unknown_product_and_unsafe_images_are_rejected(): void
    {
        $s = app(Store::class)->read();
        $this->expectException(ApiError::class);
        app(CatalogEditor::class)->update($s, 'webSlides', [['id' => 'x', 'name' => 'Bad', 'src' => 'javascript:alert(1)', 'productId' => 999]]);
    }
}
