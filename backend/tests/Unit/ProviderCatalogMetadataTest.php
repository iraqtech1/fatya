<?php

namespace Tests\Unit;

use App\Services\ProviderCatalogMetadata;
use Tests\TestCase;

class ProviderCatalogMetadataTest extends TestCase
{
    public function test_catalog_fields_images_description_and_zero_values_are_preserved_as_data(): void
    {
        $m = ProviderCatalogMetadata::read(['price' => '01234.500', 'description' => '<p>Weekly &amp; data</p>', 'duration_days' => 7, 'sms' => 0, 'available' => false, 'images' => [['alt' => 'Fixture label', 'url' => '/media/card.png'], ['url' => 'https://cdn.example.com/card.webp']], 'options' => ['region' => 'Iraq'], 'photo_url' => '', 'img' => null, 'logo' => 'media/icon.png?apiKey=private-image', 'authToken' => 'private-auth', 'api_key' => 'private-fixture', 'token' => 'private-token', 'nested' => ['password' => 'private-password']], 'https://masal-co.com');
        $this->assertSame('Weekly & data', $m['description']);
        $this->assertSame(['https://masal-co.com/media/card.png', 'https://cdn.example.com/card.webp'], $m['images']);
        $fields = collect($m['supplierDetails'])->keyBy('label');
        $this->assertSame('01234.500', $fields['price']['value']);
        $this->assertSame('0', $fields['sms']['value']);
        $this->assertSame('false', $fields['available']['value']);
        $this->assertStringNotContainsString('private-', json_encode($m));
        $this->assertSame('7', collect($m['serviceInfo'])->firstWhere('label', 'duration_days')['value']);
        $this->assertNull(ProviderCatalogMetadata::image('https://cdn.example.com/a.png?api_key=private-fixture', 'https://masal-co.com'));
    }

    public function test_image_urls_reject_scripts_credentials_local_network_and_mixed_content(): void
    {
        foreach (['', '/', '#', 'javascript:alert(1)', 'data:image/png;base64,ABC', 'file:///C:/image.png', 'http://example.com/a.jpg', 'https://localhost/a', 'https://127.0.0.1/a', 'https://192.168.1.1/a', 'https://[::ffff:127.0.0.1]/a', 'https://user:pass@example.com/a', 'https://example.com:8443/a', 'https://example.local/a', 'https://127.1/a'] as $url) {
            $this->assertNull(ProviderCatalogMetadata::image($url, 'https://masal-co.com'), $url);
        }
        $this->assertSame('https://tryapi.nojoomalrabiaa.com/media/a.png', ProviderCatalogMetadata::image('/media/a.png', 'https://tryapi.nojoomalrabiaa.com/api/v2'));
        $this->assertSame('https://cdn.example.com/a.png', ProviderCatalogMetadata::image('//cdn.example.com/a.png', 'https://masal-co.com'));
    }
}
