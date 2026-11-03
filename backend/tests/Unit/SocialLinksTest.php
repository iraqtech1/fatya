<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\CatalogEditor;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SocialLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    public function test_links_persist_survive_older_editors_and_can_be_cleared(): void
    {
        $store = app(Store::class);
        $editor = app(CatalogEditor::class);
        $state = $store->read();
        $links = array_fill_keys(CatalogEditor::SOCIAL_FIELDS, 'https://example.com/owner?from=store');
        $links['whatsappUrl'] = 'https://wa.me/+9647712345678';
        $editor->update($state, 'settings', [...$state['settings'], ...$links]);
        $store->save($state);
        foreach ($links as $key => $value) {
            $this->assertSame($value, $store->read()['settings'][$key]);
        }
        $legacy = array_diff_key($state['settings'], $links);
        $editor->update($state, 'settings', [...$legacy, 'name' => 'Updated']);
        $this->assertSame($links['instagramUrl'], $state['settings']['instagramUrl']);
        $editor->update($state, 'settings', [...$state['settings'], 'instagramUrl' => '']);
        $store->save($state);
        $this->assertSame('', $store->read()['settings']['instagramUrl']);
        $this->assertSame($links['whatsappUrl'], $store->read()['settings']['whatsappUrl']);
    }

    public function test_unsafe_and_invalid_links_are_rejected_before_settings_change(): void
    {
        $state = app(Store::class)->read();
        foreach (['javascript:alert(1)', 'data:text/html,hello', 'ftp://example.com', 'https://user:password@example.com', 'not a link', str_repeat('x', 2049)] as $invalid) {
            $before = $state;
            try {
                app(CatalogEditor::class)->update($state, 'settings', [...$state['settings'], 'facebookUrl' => $invalid]);
                $this->fail('Invalid social link was accepted');
            } catch (ApiError) {
                $this->assertSame($before, $state);
            }
        }
    }
}
