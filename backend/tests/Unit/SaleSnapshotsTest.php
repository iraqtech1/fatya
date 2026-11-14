<?php

namespace Tests\Unit;

use App\Services\SaleSnapshots;
use PHPUnit\Framework\TestCase;

class SaleSnapshotsTest extends TestCase
{
    public function test_display_snapshot_is_independent_and_allowlisted(): void
    {
        $product = ['id' => 7, 'name' => 'Original', 'unit' => 'IQD', 'values' => [5000], 'prices' => [5250], 'denomNames' => ['Original category'], 'manufacturerId' => 'company-1', 'translations' => ['en' => ['name' => 'Original English', 'denomNames' => ['Five thousand'], 'credential' => 'secret']], 'cost' => '4500', 'pin' => 'never-copy'];
        $snapshot = SaleSnapshots::capture($product, 0, 2, '10500.125');
        $product['name'] = 'Renamed';
        $product['values'][0] = 9000;
        $product['translations']['en']['name'] = 'Renamed English';
        $this->assertSame('Original', $snapshot['name']);
        $this->assertSame(5000, $snapshot['denominationValue']);
        $this->assertSame('Original English', $snapshot['translations']['en']['name']);
        $this->assertSame('10500.125', $snapshot['total']);
        $this->assertSame('company-1', $snapshot['companyId']);
        $this->assertStringNotContainsString('secret', json_encode($snapshot));
        $this->assertArrayNotHasKey('cost', $snapshot);
        $this->assertArrayNotHasKey('pin', $snapshot);
    }

    public function test_api_service_keeps_provider_identity_without_exposing_cost(): void
    {
        $snapshot = SaleSnapshots::capture(['id' => 1, 'name' => 'Company', 'unit' => 'خدمة', 'values' => [null]], 0, 1, '5250', ['providerId' => '00071', 'type' => 'bundle', 'title' => 'Weekly bundle', 'cost' => '4500', 'encryptedSource' => 'private']);
        $this->assertSame('00071', $snapshot['providerId']);
        $this->assertNull($snapshot['denominationValue']);
        $this->assertSame('Weekly bundle', $snapshot['serviceTitle']);
        $this->assertSame('api', $snapshot['source']);
        $this->assertSame('5250.000', $snapshot['total']);
        $this->assertArrayNotHasKey('encryptedSource', $snapshot);
    }
}
