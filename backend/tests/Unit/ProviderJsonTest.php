<?php

namespace Tests\Unit;

use App\Services\ProviderJson;
use JsonException;
use PHPUnit\Framework\TestCase;

class ProviderJsonTest extends TestCase
{
    public function test_money_is_preserved_before_float_conversion_without_rewriting_strings(): void
    {
        $body = <<<'JSON'
{"price":99999999999999999.123,"data":{"publicPrice":4300.75,"vendorPrice":-4500.25},"id":99999999999999999999,"note":"quoted \"price\": 0.01","serial":"000123"}
JSON;
        $data = ProviderJson::decode($body);
        $this->assertSame('99999999999999999.123', $data['price']);
        $this->assertSame('4300.75', $data['data']['publicPrice']);
        $this->assertSame('-4500.25', $data['data']['vendorPrice']);
        $this->assertSame('99999999999999999999', $data['id']);
        $this->assertSame('quoted "price": 0.01', $data['note']);
        $this->assertSame('000123', $data['serial']);
        $this->assertSame('99999999999999999.123', ProviderJson::decode('99999999999999999.123'));
    }

    public function test_invalid_json_never_becomes_a_valid_catalog(): void
    {
        $this->expectException(JsonException::class);
        ProviderJson::decode('{"price":12.34,}');
    }
}
