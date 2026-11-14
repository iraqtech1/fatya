<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\CatalogEditor;
use App\Services\CompanyHierarchy;
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

    public function test_branch_paths_preserve_ids_and_reject_cycles_without_mutating_the_tree(): void
    {
        $root = ['id' => 'company', 'name' => 'Company', 'role' => 'manufacturer', 'active' => true, 'image' => ''];
        $branch = [...$root, 'id' => 'branch', 'name' => 'Branch', 'parentId' => 'company'];
        $state = ['companies' => [$root, $branch], 'products' => []];
        app(CatalogEditor::class)->update($state, 'companies', [...$branch, 'translations' => ['en' => ['name' => 'English branch', 'description' => 'Description']]]);
        $this->assertSame(['company', 'branch'], array_column(CompanyHierarchy::path($state['companies'], 'branch'), 'id'));
        $before = $state;
        try {
            app(CatalogEditor::class)->update($state, 'companies', [...$root, 'parentId' => 'branch']);
            $this->fail('Cycle accepted');
        } catch (ApiError $error) {
            $this->assertSame(422, $error->status);
        }
        $this->assertSame($before, $state);
        $this->assertSame('English branch', $state['companies'][1]['translations']['en']['name']);
        $this->assertArrayNotHasKey('phone', CompanyHierarchy::path($state['companies'], 'branch')[0]);
    }
}
