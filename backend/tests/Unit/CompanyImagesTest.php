<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\CatalogEditor;
use Tests\TestCase;

class CompanyImagesTest extends TestCase
{
    public function test_existing_production_company_images_survive_name_edits_and_can_be_cleared(): void
    {
        $company = ['id' => 'existing', 'name' => 'Company', 'role' => 'manufacturer', 'active' => true, 'image' => 'cards/company.webp'];
        $s = ['companies' => [$company], 'products' => []];
        $editor = app(CatalogEditor::class);
        $data = $company;
        unset($data['image']);
        $editor->update($s, 'companies', [...$data, 'name' => 'Updated']);
        $this->assertSame('cards/company.webp', $s['companies'][0]['image']);
        $editor->update($s, 'companies', [...$s['companies'][0], 'image' => '']);
        $this->assertSame('', $s['companies'][0]['image']);
    }

    public function test_unsafe_company_images_cannot_be_saved(): void
    {
        $s = ['companies' => [], 'products' => []];
        $this->expectException(ApiError::class);
        app(CatalogEditor::class)->update($s, 'companies', ['name' => 'Company', 'role' => 'manufacturer', 'active' => true, 'image' => 'javascript:alert(1)']);
    }
}
