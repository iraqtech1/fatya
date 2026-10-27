<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\CustomerNotifications;
use App\Services\PushDelivery;
use App\Services\Takeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MessagingTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateCollation('utf8mb4_bin', strcmp(...));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['fatya.takeed_key' => 'test-only-secret', 'fatya.takeed_app_id' => 'test-app', 'fatya.otp_provider' => 'takeed', 'fatya.development_otp' => false, 'fatya.require_registration_otp' => true]);
        Http::preventStrayRequests();
    }

    private function register(): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/auth/register', ['phone' => '07712345678', 'name' => 'Test Customer', 'email' => '', 'password' => 'test-password']);
    }

    public function test_registration_waits_for_otp_and_only_stores_password_hash(): void
    {
        $code = '';
        Http::fake(function ($r) use (&$code) {
            $code = $r['code'];
            $this->assertSame('+9647712345678', $r['phone']);
            $this->assertSame('1.1.1.1', $r['user_ip']);
            $this->assertTrue($r->hasHeader('Authorization', 'Key test-only-secret'));

            return Http::response(['id' => 'otp-test', 'mode' => 'deliver'], 202);
        });
        $response = $this->register()->assertOk()->assertJsonMissingPath('previewCode');
        $id = $response->json('challengeId');
        $this->assertDatabaseCount('customers', 0);
        $payload = json_decode(DB::table('otp_challenges')->value('payload'), true);
        $this->assertTrue(Hash::check('test-password', $payload['passwordHash']));
        $this->assertNotSame($code, $payload['code']);
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/auth/verify', ['challengeId' => $id, 'code' => '000000'])->assertStatus(400);
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/auth/verify', ['challengeId' => $id, 'code' => $code])->assertOk()->assertJsonMissingPath('passwordHash');
        $this->assertDatabaseCount('customers', 1);
        $this->assertTrue(Hash::check('test-password', DB::table('customer_credentials')->value('password')));
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->withHeaders(['X-Fatya-Request' => '1'])->postJson('/api/auth/verify', ['challengeId' => $id, 'code' => $code])->assertStatus(400);
    }

    public function test_delivery_failure_does_not_create_an_account_or_challenge(): void
    {
        Http::fake(['*' => Http::response(['errors' => [['code' => 'application_credit_exhausted']]], 402)]);
        $this->register()->assertStatus(503)->assertJsonMissingPath('previewCode');
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_push_is_private_retryable_and_not_sent_before_commit(): void
    {
        $id = 'test-customer';
        DB::table('customers')->insert(['id' => $id, 'position' => 0, 'payload' => '{}']);
        $notifications = app(CustomerNotifications::class);
        $notifications->send($id, 'تم تحديث الطلب', 'PRIVATE-ORDER-DETAILS', 'one-event');
        $notifications->send($id, 'تم تحديث الطلب', 'duplicate', 'one-event');
        $this->assertDatabaseCount('customer_notifications', 1);
        Http::assertNothingSent();
        Http::fake(['*' => Http::response([], 503)]);
        $this->assertSame(1, app(PushDelivery::class)->run()['retry']);
        DB::table('customer_notifications')->update(['push_after' => 0]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($r) use ($id) {
            $this->assertSame([app(Takeed::class)->alias($id)], $r['include_aliases']['external_id']);
            $this->assertArrayNotHasKey('included_segments', $r->data());
            $this->assertStringNotContainsString('PRIVATE-ORDER-DETAILS', $r->body());

            return Http::response(['id' => 'provider-notification'], 202);
        });
        $this->assertSame(1, app(PushDelivery::class)->run()['sent']);
        $this->assertSame(0, app(PushDelivery::class)->run()['sent']);
        $this->assertNotSame(app(Takeed::class)->alias($id), app(Takeed::class)->alias('other'));
        $this->getJson('/api/push/config')->assertStatus(401);
        $this->assertArrayNotHasKey('key', app(Takeed::class)->clientConfig($id));
    }

    public function test_otp_balance_is_admin_only_and_zero_is_distinct_from_provider_failure(): void
    {
        Http::fake(['*' => Http::response(['available_messages' => 0], 200)]);
        $this->getJson('/api/admin/otp/balance')->assertStatus(401);
        Http::assertNothingSent();
        $this->assertSame(0, app(Takeed::class)->balance()['availableMessages']);
        $this->assertSame(0, app(Takeed::class)->balance()['availableMessages']);
        Http::assertSentCount(1);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->expectException(ApiError::class);
        app(Takeed::class)->balance(true);
    }
}
