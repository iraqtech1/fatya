<?php

namespace Tests\Unit;

use App\Services\Accounts;
use Tests\TestCase;

class AccountsTest extends TestCase
{
    public function test_iraqi_phone_formats_are_preserved(): void
    {
        foreach (['+964 771 234 5678', '٠٧٧١٢٣٤٥٦٧٨', '۰۷۷۱۲۳۴۵۶۷۸', '009647712345678'] as $phone) {
            $this->assertSame('07712345678', Accounts::phone($phone));
        }
    }

    public function test_node_scrypt_compatibility_uses_original_ascii_salt(): void
    {
        $stored = 'node-scrypt:0123456789abcdef0123456789abcdef:e11d84370437e946801cf04dc2e60f9d56c696beffe896092cee0e389d23d783195c0103895cb13101e58702b2d8eb62e38a7baa5bcfd17e4469a9dddd710ca8';
        $accounts = new Accounts;
        $this->assertTrue($accounts->checkPassword('test-password', $stored));
        $this->assertFalse($accounts->checkPassword('wrong-password', $stored));
    }
}
