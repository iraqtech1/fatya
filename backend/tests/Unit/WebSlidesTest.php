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

    public function test_faqs_persist_reorder_and_remain_independent_of_other_settings(): void
    {
        $store = app(Store::class);
        $editor = app(CatalogEditor::class);
        $s = $store->read();
        $s['settings']['webSlides'] = [];
        $rows = [['id' => 'q1', 'question' => 'First?', 'answer' => "First answer\nsecond line"], ['id' => 'q2', 'question' => 'Second?', 'answer' => 'Second answer']];
        $editor->update($s, 'faqs', $rows);
        $store->save($s);
        $this->assertSame($rows, $store->read()['settings']['faqs']);
        $editor->update($s, 'settings', [...$s['settings'], 'name' => 'Updated']);
        $store->save($s);
        $this->assertSame($rows, $store->read()['settings']['faqs']);
        $this->assertSame([], $store->read()['settings']['webSlides']);
        $editor->update($s, 'faqs', array_reverse($rows));
        $store->save($s);
        $this->assertSame('q2', $store->read()['settings']['faqs'][0]['id']);
        $editor->update($s, 'faqs', []);
        $store->save($s);
        $this->assertSame([], $store->read()['settings']['faqs']);
        $this->withHeaders(['X-Fatya-Request' => '1'])->putJson('/api/admin/faqs', ['revision' => $s['revision'], 'data' => $rows])->assertStatus(401);
    }

    public function test_empty_faq_fields_are_rejected(): void
    {
        $s = app(Store::class)->read();
        $this->expectException(ApiError::class);
        app(CatalogEditor::class)->update($s, 'faqs', [['id' => 'q1', 'question' => ' ', 'answer' => 'Answer']]);
    }
}
