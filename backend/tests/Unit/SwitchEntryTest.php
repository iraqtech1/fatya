<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SwitchEntryTest extends TestCase
{
    private string $publicDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->publicDirectory = sys_get_temp_dir().'/fatya-switch-'.bin2hex(random_bytes(8));
        mkdir($this->publicDirectory);
        file_put_contents($this->publicDirectory.'/switch.html', '<html><title>Fatya Switch</title><div id="app"></div></html>');
        $this->app->usePublicPath($this->publicDirectory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        unlink($this->publicDirectory.'/switch.html');
        rmdir($this->publicDirectory);
        parent::tearDown();
    }

    private function launch(string $token): string
    {
        $r = $this->get('https://localhost/api/switch/launch?token='.rawurlencode($token).'&unused=discard');
        $r->assertStatus(303)->assertRedirect('/api/switch/launch')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringNotContainsString($token, $r->getContent());
        $cookie = collect($r->headers->getCookies())->first(fn ($c) => $c->getName() === 'fatya_switch_entry');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/api/switch', $cookie->getPath());
        $this->assertNotSame($token, $cookie->getValue());

        return $cookie->getValue();
    }

    public function test_opaque_token_is_encrypted_and_never_becomes_customer_or_payment_authority(): void
    {
        $token = 'synthetic-test-token-123456';
        $handle = $this->launch($token);
        $saved = Cache::get('fatya:switch:entry:'.hash('sha256', $handle));
        $this->assertNotSame($token, $saved['encrypted_token']);
        $this->assertSame($token, Crypt::decryptString($saved['encrypted_token']));
        $this->withUnencryptedCookie('fatya_switch_entry', $handle)->getJson('https://localhost/api/switch/session')->assertOk()->assertExactJson(['state' => 'pending_activation', 'paymentEnabled' => false])->assertHeader('Referrer-Policy', 'no-referrer');
        $page = $this->get('https://localhost/api/switch/launch')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $html = file_get_contents($page->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('Fatya Switch', $html);
        $this->assertStringNotContainsString($token, $html);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->assertNull(request()->cookie('masal_customer'));
        Http::assertNothingSent();
    }

    public function test_missing_expired_and_replaced_entries_do_not_restore_previous_tokens(): void
    {
        $this->getJson('https://localhost/api/switch/session')->assertExactJson(['state' => 'missing', 'paymentEnabled' => false]);
        $old = $this->launch('old-test-token');
        $this->withUnencryptedCookie('fatya_switch_entry', $old);
        $new = $this->launch('new-test-token');
        $this->assertNull(Cache::get('fatya:switch:entry:'.hash('sha256', $old)));
        $this->withUnencryptedCookie('fatya_switch_entry', $new);
        $this->travel(11)->minutes();
        $this->getJson('https://localhost/api/switch/session')->assertExactJson(['state' => 'expired', 'paymentEnabled' => false]);
    }

    public function test_invalid_tokens_are_discarded_without_echo_and_launch_is_limited(): void
    {
        foreach (['token=', 'token[]=bad', 'token=bad%0Avalue', 'token='.str_repeat('x', 8193)] as $query) {
            $this->get('https://localhost/api/switch/launch?'.$query)->assertStatus(303)->assertRedirect('/api/switch/launch');
        }
        for ($i = 0; $i < 16; $i++) {
            $this->get('https://localhost/api/switch/launch?token=test')->assertStatus(303);
        }
        $this->get('https://localhost/api/switch/launch?token=test')->assertStatus(429);
        $this->get('https://localhost/api/switch/launch')->assertOk();
    }

    public function test_production_entry_requires_https(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('http://localhost/api/switch/launch?token=synthetic')->assertStatus(400)->assertDontSee('synthetic');
        $this->getJson('http://localhost/api/switch/session')->assertStatus(400)->assertJsonPath('state', 'unavailable');
    }
}
