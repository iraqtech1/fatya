<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\AdminAccess;
use App\Services\AdminPolicy;
use App\Services\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminAccessTest extends TestCase
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
        config(['fatya.admin_user' => 'owner', 'fatya.admin_password' => 'owner-fixture-password-123', 'hashing.driver' => 'bcrypt']);
        $this->withHeaders(['X-Fatya-Request' => '1']);
    }

    private function owner(): void
    {
        $this->withCredentials()->withUnencryptedCookie('masal_admin', app(Accounts::class)->issue('admin')->getValue());
    }

    private function role(array $permissions = [], bool $full = false): string
    {
        $id = (string) Str::uuid();
        DB::table('admin_roles')->insert(['id' => $id, 'name' => $id, 'description' => '', 'permissions' => json_encode($permissions), 'unrestricted' => $full, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function employee(array $permissions = [], bool $full = false): array
    {
        $role = $this->role($permissions, $full);
        $id = (string) Str::uuid();
        $username = 'staff-'.substr($id, 0, 8);
        DB::table('admin_staff')->insert(['id' => $id, 'name' => 'موظف اختبار', 'username' => $username, 'password' => Hash::make('employee-fixture-password-123'), 'role_id' => $role, 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return ['id' => $id, 'username' => $username, 'roleId' => $role, 'name' => 'موظف اختبار', 'active' => true, 'version' => 1];
    }

    private function asEmployee(array $employee): string
    {
        $token = app(Accounts::class)->issue('admin', null, $employee['id'])->getValue();
        $this->withCredentials()->withUnencryptedCookie('masal_admin', $token);

        return $token;
    }

    public function test_owner_can_create_named_role_and_employee_then_employee_signs_in_with_own_password(): void
    {
        $this->owner();
        $role = $this->postJson('/api/admin/access/roles/save', ['name' => 'الدعم', 'description' => 'قراءة ورد فقط', 'unrestricted' => false, 'permissions' => ['support.view', 'support.reply']])->assertOk()->json('role');
        $body = ['name' => 'موظف الدعم', 'username' => 'support.one', 'roleId' => $role['id'], 'active' => true, 'password' => 'private-fixture-password-123'];
        $staff = $this->postJson('/api/admin/access/staff/save', $body)->assertOk()->assertJsonMissingPath('staff.password')->json('staff');
        $this->assertNotSame($body['password'], DB::table('admin_staff')->where('id', $staff['id'])->value('password'));
        $response = $this->postJson('/api/admin/login', ['username' => 'SUPPORT.ONE', 'password' => $body['password']])->assertOk();
        $cookie = $response->getCookie('masal_admin', false)->getValue();
        $this->withUnencryptedCookie('masal_admin', $cookie)->getJson('/api/admin/session')->assertOk()->assertJsonPath('access.owner', false)->assertJsonPath('access.roleName', 'الدعم')->assertJsonPath('access.permissions', ['support.view', 'support.reply']);
        $audit = DB::table('admin_access_audit')->get()->toJson();
        $this->assertStringNotContainsString($body['password'], $audit);
        $this->getJson('/api/admin/access/staff')->assertForbidden();
    }

    public function test_staff_and_customer_cannot_bypass_permissions_by_calling_direct_endpoints(): void
    {
        $employee = $this->employee(['support.view']);
        $this->asEmployee($employee);
        foreach ([['PUT', '/api/admin/settings', ['data' => []]], ['POST', '/api/admin/import', ['test' => true]], ['POST', '/api/admin/procurement/digital/manual-payment', ['test' => true]], ['POST', '/api/admin/access/roles/save', ['test' => true]], ['POST', '/api/admin/procurement/future-operation', ['test' => true]]] as [$method, $url, $body]) {
            $this->json($method, $url, $body)->assertForbidden();
        }
        $this->getJson('/api/admin/state')->assertOk()->assertJsonPath('adminAccess.username', $employee['username']);
        $this->assertDatabaseHas('admin_access_audit', ['actor' => $employee['username'], 'action' => 'permission.denied']);
        $this->withUnencryptedCookie('masal_admin', 'invalid')->getJson('/api/admin/access/roles')->assertUnauthorized();
    }

    public function test_ungranted_records_and_financial_data_are_removed_from_snapshot_and_mutation_responses(): void
    {
        $s = app(Store::class)->read();
        $customer = Accounts::newCustomer(['name' => 'Private customer', 'email' => 'private@example.test'], '07712345678');
        $s['customers'] = [$customer];
        $s['tickets'] = [['id' => 'test-ticket', 'customerId' => $customer['id'], 'customer' => $customer['name'], 'phone' => $customer['phone'], 'email' => $customer['email'], 'subject' => 'Test', 'message' => 'Test body', 'reply' => '', 'status' => 'مفتوحة', 'date' => '2026-11-03']];
        app(Store::class)->save($s);
        $this->asEmployee($this->employee(['support.view', 'support.reply']));
        $state = $this->getJson('/api/admin/state')->assertOk()->assertJsonPath('customers', [])->assertJsonPath('orders', [])->assertJsonPath('events', [])->assertJsonPath('tickets.0.phone', '')->assertJsonPath('tickets.0.email', '')->json();
        $this->putJson('/api/admin/tickets', ['revision' => $state['revision'], 'data' => [...$state['tickets'][0], 'reply' => 'مساعدة']])->assertOk()->assertJsonPath('customers', [])->assertJsonPath('tickets.0.phone', '');
        $this->putJson('/api/admin/tickets', ['revision' => app(Store::class)->read()['revision'], 'data' => [...$state['tickets'][0], 'status' => 'مغلقة']])->assertForbidden();
        $this->assertSame('مفتوحة', app(Store::class)->read()['tickets'][0]['status']);
    }

    public function test_setting_permissions_apply_to_each_changed_field_and_deny_mixed_payload_atomically(): void
    {
        $this->asEmployee($this->employee(['settings.view', 'settings.identity']));
        $state = $this->getJson('/api/admin/state')->assertOk()->json();
        $this->putJson('/api/admin/settings', ['revision' => $state['revision'], 'data' => [...$state['settings'], 'name' => 'Allowed']])->assertOk();
        $state = $this->getJson('/api/admin/state')->json();
        $this->putJson('/api/admin/settings', ['revision' => $state['revision'], 'data' => [...$state['settings'], 'name' => 'Must roll back', 'lowStock' => 123]])->assertForbidden();
        $this->assertSame('Allowed', app(Store::class)->read()['settings']['name']);
    }

    public function test_delegated_manager_cannot_create_or_assign_a_more_powerful_role_or_impersonate_owner(): void
    {
        $manager = $this->employee(['roles.view', 'roles.create', 'staff.create', 'staff.assign']);
        $powerful = $this->role(['inventory.codes']);
        $this->asEmployee($manager);
        foreach ([['unrestricted' => true, 'permissions' => []], ['unrestricted' => false, 'permissions' => ['inventory.codes']], ['unrestricted' => false, 'permissions' => ['unknown.permission']]] as $grant) {
            $this->postJson('/api/admin/access/roles/save', ['name' => 'Escalation', ...$grant])->assertStatus(in_array('unknown.permission', $grant['permissions'], true) ? 422 : 403);
        }
        $this->postJson('/api/admin/access/staff/save', ['name' => 'Escalation', 'username' => 'new.staff', 'roleId' => $powerful, 'active' => true, 'password' => 'fixture-password-123'])->assertForbidden();
        $this->postJson('/api/admin/access/roles/save', ['name' => 'Limited', 'description' => '', 'unrestricted' => false, 'permissions' => ['roles.view']])->assertOk();
    }

    public function test_role_change_and_employee_disable_revoke_existing_sessions(): void
    {
        $employee = $this->employee(['support.view']);
        $oldToken = $this->asEmployee($employee);
        $this->getJson('/api/admin/session')->assertOk();
        $this->owner();
        $this->postJson('/api/admin/access/roles/save', ['id' => $employee['roleId'], 'version' => 1, 'name' => 'Changed', 'description' => '', 'unrestricted' => false, 'permissions' => []])->assertOk();
        $this->withUnencryptedCookie('masal_admin', $oldToken)->getJson('/api/admin/session')->assertUnauthorized();
        $token = $this->asEmployee($employee);
        $this->owner();
        $this->postJson('/api/admin/access/staff/save', [...$employee, 'active' => false, 'password' => ''])->assertOk();
        $this->withUnencryptedCookie('masal_admin', $token)->getJson('/api/admin/state')->assertUnauthorized();
        $this->postJson('/api/admin/login', ['username' => $employee['username'], 'password' => 'employee-fixture-password-123'])->assertUnauthorized();
    }

    public function test_reports_export_and_secret_download_are_separate_permissions(): void
    {
        $this->asEmployee($this->employee(['reports_inventory.view', 'exports.download']));
        $this->postJson('/api/admin/procurement/reports', ['type' => 'inventory', 'filters' => []])->assertOk()->assertJsonMissingPath('totals.availableCostKnown');
        $this->postJson('/api/admin/procurement/reports', ['type' => 'inventory', 'all' => true, 'filters' => []])->assertForbidden();
        $this->postJson('/api/admin/procurement/reports', ['type' => 'payments', 'filters' => []])->assertForbidden();
        $this->postJson('/api/admin/procurement/exports/download', ['id' => 'anything'])->assertForbidden();
    }

    public function test_role_deletion_in_use_and_stale_edits_are_rejected_and_no_password_is_returned(): void
    {
        $employee = $this->employee();
        $this->owner();
        $this->postJson('/api/admin/access/roles/delete', ['id' => $employee['roleId'], 'version' => 1])->assertConflict();
        $this->postJson('/api/admin/access/staff/save', [...$employee, 'version' => 0])->assertConflict();
        $this->postJson('/api/admin/access/staff/save', [...$employee, 'username' => 'OWNER'])->assertUnprocessable();
        $this->getJson('/api/admin/access/staff')->assertOk()->assertJsonMissingPath('staff.0.password');
        $this->postJson('/api/admin/access/staff/delete', ['id' => $employee['id'], 'version' => 1])->assertOk();
        $this->getJson('/api/admin/access/staff')->assertJsonCount(0, 'staff');
        $this->assertNotNull(DB::table('admin_staff')->where('id', $employee['id'])->value('deleted_at'));
    }

    public function test_sensitive_confirmation_uses_employee_password_not_owner_password(): void
    {
        $employee = $this->employee(['exports.copy']);
        $this->asEmployee($employee);
        $this->getJson('/api/admin/session')->assertOk();
        $this->assertTrue(app(AdminAccess::class)->confirmPassword('employee-fixture-password-123'));
        $this->assertFalse(app(AdminAccess::class)->confirmPassword('owner-fixture-password-123'));
    }

    public function test_submit_cannot_bypass_approval_permission_when_review_policy_is_disabled(): void
    {
        DB::table('procurement_settings')->where('id', 1)->update(['approval_required' => false]);
        $this->asEmployee($this->employee(['stock_orders.submit']));
        $this->postJson('/api/admin/procurement/stock-orders/submit', ['test' => true])->assertForbidden();
    }

    public function test_catalog_editor_cannot_smuggle_prices_or_visibility_with_a_name_change(): void
    {
        $this->artisan('fatya:seed')->assertSuccessful();
        $this->asEmployee($this->employee(['products.view', 'products.update']));
        $state = $this->getJson('/api/admin/state')->assertOk()->json();
        $product = $state['products'][0];
        $response = $this->putJson('/api/admin/products', ['revision' => $state['revision'], 'data' => [...$product, 'name' => 'اسم مسموح']]);
        $this->assertSame(200, $response->status(), $response->getContent());
        $state = $this->getJson('/api/admin/state')->json();
        $product = $state['products'][0];
        $product['prices'][0] += 500;
        $this->putJson('/api/admin/products', ['revision' => $state['revision'], 'data' => $product])->assertForbidden();
        $product['prices'][0] -= 500;
        $product['active'] = ! $product['active'];
        $this->putJson('/api/admin/products', ['revision' => $state['revision'], 'data' => $product])->assertForbidden();
        $product['active'] = '1';
        $this->putJson('/api/admin/products', ['revision' => $state['revision'], 'data' => $product])->assertForbidden();
    }

    public function test_digital_price_permission_does_not_also_grant_publication_or_connection_changes(): void
    {
        $this->asEmployee($this->employee(['digital.view', 'digital.prices']));
        $this->getJson('/api/admin/session')->assertOk();
        $old = (object) ['product_id' => '1', 'provider_id' => '22', 'connection' => 'topup', 'retail' => '100.000', 'active' => false];
        $next = ['product_id' => '1', 'provider_id' => '22', 'connection' => 'topup', 'retail' => '200', 'active' => false];
        app(AdminPolicy::class)->digitalOffer($old, $next, true);
        foreach ([['active' => true], ['provider_id' => '33']] as $changes) {
            try {
                app(AdminPolicy::class)->digitalOffer($old, [...$next, ...$changes], true);
                $this->fail('A price editor must not change visibility or mapping');
            } catch (ApiError $error) {
                $this->assertSame(403, $error->status);
            }
        }
        $this->putJson('/api/admin/procurement/digital/connections', ['provider' => 'topup'])->assertForbidden();
    }

    public function test_social_links_require_contact_permission_and_persist_through_api(): void
    {
        $employee = $this->employee(['settings.view', 'settings.identity']);
        $this->asEmployee($employee);
        $state = app(Store::class)->read();
        $body = ['revision' => $state['revision'], 'data' => [...$state['settings'], 'facebookUrl' => 'https://facebook.com/store']];
        $this->putJson('/api/admin/settings', $body)->assertForbidden();
        $this->assertSame('', app(Store::class)->read()['settings']['facebookUrl']);
        $employee = $this->employee(['settings.view', 'settings.contacts']);
        $this->asEmployee($employee);
        $this->putJson('/api/admin/settings', $body)->assertOk()->assertJsonPath('settings.facebookUrl', 'https://facebook.com/store');
        $this->getJson('/api/state')->assertOk()->assertJsonPath('settings.facebookUrl', 'https://facebook.com/store');
    }

    public function test_manager_profile_email_login_role_count_and_archiving(): void
    {
        $this->owner();
        $role = $this->role(['support.view']);
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
        $body = ['name' => 'مدير الدعم', 'username' => 'manager.profile', 'email' => 'Manager@Example.test', 'about' => 'مسؤول الدعم', 'image' => $image, 'password' => 'profile-fixture-password-123', 'roleId' => $role, 'active' => true];
        $staff = $this->postJson('/api/admin/access/staff/save', $body)->assertOk()->assertJsonPath('staff.email', 'manager@example.test')->assertJsonPath('staff.about', 'مسؤول الدعم')->assertJsonPath('staff.image', $image)->json('staff');
        $this->assertNotEmpty($staff['createdAt']);
        $this->getJson('/api/admin/access/roles')->assertOk()->assertJsonPath('roles.0.staffCount', 1);
        $this->postJson('/api/admin/access/staff/save', [...$body, 'username' => 'another.manager'])->assertConflict();
        $login = $this->postJson('/api/admin/login', ['username' => 'MANAGER@EXAMPLE.TEST', 'password' => $body['password']])->assertOk();
        $this->withUnencryptedCookie('masal_admin', $login->getCookie('masal_admin', false)->getValue())->getJson('/api/admin/session')->assertJsonPath('access.roleId', $role)->assertJsonPath('access.permissions', ['support.view']);
        $this->owner();
        $this->postJson('/api/admin/access/staff/delete', ['id' => $staff['id'], 'version' => 1])->assertOk();
        $this->getJson('/api/admin/access/staff')->assertJsonCount(0, 'staff');
        $this->getJson('/api/admin/access/roles')->assertJsonPath('roles.0.staffCount', 0);
        $this->postJson('/api/admin/login', ['username' => 'manager@example.test', 'password' => $body['password']])->assertUnauthorized();
    }

    public function test_manager_profile_cannot_bypass_field_permissions_or_accept_invalid_image(): void
    {
        $target = $this->employee(['support.view']);
        $editor = $this->employee(['staff.view', 'staff.update', 'support.view']);
        $this->asEmployee($editor);
        $this->postJson('/api/admin/access/staff/save', [...$target, 'email' => 'new@example.test'])->assertForbidden();
        $saved = $this->postJson('/api/admin/access/staff/save', [...$target, 'about' => 'Updated profile'])->assertOk()->json('staff');
        $this->assertSame('Updated profile', $saved['about']);
        $this->owner();
        foreach (['data:image/svg+xml;base64,PHN2Zz48L3N2Zz4=', 'https://example.com/photo.jpg', 'data:image/png;base64,YmFk'] as $image) {
            $this->postJson('/api/admin/access/staff/save', [...$saved, 'image' => $image])->assertUnprocessable();
        }
        $this->assertNull(DB::table('admin_staff')->where('id', $target['id'])->value('image'));
    }

    public function test_my_account_only_updates_current_employee_and_requires_current_password_for_login_changes(): void
    {
        $employee = $this->employee(['my_account.view', 'my_account.update', 'my_account.password']);
        $oldToken = $this->asEmployee($employee);
        $profile = $this->getJson('/api/admin/access/account')->assertOk()->assertJsonMissingPath('account.password')->json('account');
        $this->postJson('/api/admin/access/account/save', [...$profile, 'email' => 'self@example.test'])->assertForbidden();
        $response = $this->postJson('/api/admin/access/account/save', [...$profile, 'name' => 'Self', 'about' => 'My profile', 'email' => 'self@example.test', 'password' => 'new-fixture-password-123', 'currentPassword' => 'employee-fixture-password-123', 'roleId' => $this->role([], true), 'unrestricted' => true, 'id' => 'owner'])->assertOk();
        $this->assertSame($employee['roleId'], DB::table('admin_staff')->where('id', $employee['id'])->value('role_id'));
        $this->withUnencryptedCookie('masal_admin', $oldToken)->getJson('/api/admin/session')->assertUnauthorized();
        $this->withUnencryptedCookie('masal_admin', $response->getCookie('masal_admin', false)->getValue())->getJson('/api/admin/access/account')->assertOk()->assertJsonPath('account.name', 'Self');
        $this->postJson('/api/admin/login', ['username' => 'self@example.test', 'password' => 'new-fixture-password-123'])->assertOk();
        $this->postJson('/api/admin/login', ['username' => $employee['username'], 'password' => 'employee-fixture-password-123'])->assertUnauthorized();
    }

    public function test_my_account_permissions_and_conflicts_are_enforced(): void
    {
        $employee = $this->employee(['my_account.view']);
        $this->asEmployee($employee);
        $profile = $this->getJson('/api/admin/access/account')->assertOk()->json('account');
        $this->postJson('/api/admin/access/account/save', [...$profile, 'name' => 'Denied'])->assertForbidden();
        $employee = $this->employee(['my_account.view', 'my_account.update']);
        $this->asEmployee($employee);
        $profile = $this->getJson('/api/admin/access/account')->json('account');
        $this->postJson('/api/admin/access/account/save', [...$profile, 'about' => 'Allowed'])->assertOk();
        $this->postJson('/api/admin/access/account/save', [...$profile, 'name' => 'Stale'])->assertConflict();
        $profile = $this->getJson('/api/admin/access/account')->json('account');
        $this->postJson('/api/admin/access/account/save', [...$profile, 'email' => 'denied@example.test', 'currentPassword' => 'employee-fixture-password-123'])->assertForbidden();
    }

    public function test_owner_profile_and_password_persist_without_modifying_environment_credentials(): void
    {
        $this->owner();
        $profile = $this->getJson('/api/admin/access/account')->assertOk()->json('account');
        $this->postJson('/api/admin/access/account/save', [...$profile, 'name' => 'Owner Name', 'email' => 'owner@example.test', 'password' => 'new-owner-fixture-password', 'currentPassword' => 'owner-fixture-password-123'])->assertOk()->assertJsonMissingPath('account.password');
        $this->postJson('/api/admin/login', ['username' => 'owner', 'password' => 'owner-fixture-password-123'])->assertUnauthorized();
        $login = $this->postJson('/api/admin/login', ['username' => 'owner@example.test', 'password' => 'new-owner-fixture-password'])->assertOk();
        $this->withUnencryptedCookie('masal_admin', $login->getCookie('masal_admin', false)->getValue())->getJson('/api/admin/session')->assertJsonPath('access.owner', true)->assertJsonPath('access.name', 'Owner Name');
        $this->assertSame('owner-fixture-password-123', config('fatya.admin_password'));
        $this->assertNotSame('new-owner-fixture-password', DB::table('admin_owner_profile')->value('password'));
        $this->assertStringNotContainsString('new-owner-fixture-password', DB::table('admin_access_audit')->get()->toJson());
    }

    public function test_owner_logs_are_visible_only_to_owner_even_for_unrestricted_managers(): void
    {
        $employee = $this->employee([], true);
        DB::table('admin_access_audit')->insert(['actor_id' => $employee['id'], 'actor' => $employee['username'], 'action' => 'login', 'subject' => null, 'details' => '{}', 'created_at' => now()]);
        for ($i = 0; $i < 55; $i++) {
            DB::table('admin_access_audit')->insert(['actor_id' => 'owner', 'actor' => 'owner', 'action' => 'operation', 'subject' => null, 'details' => '{}', 'created_at' => now()]);
        }
        foreach (['owner', $employee['username']] as $actor) {
            DB::table('procurement_audit')->insert(['actor' => $actor, 'action' => 'fixture', 'subject' => null, 'details' => '{}', 'created' => time()]);
        }
        $this->asEmployee($employee);
        $this->getJson('/api/admin/access/audit')->assertOk()->assertJsonCount(1, 'events')->assertJsonPath('events.0.actor', $employee['username']);
        $this->getJson('/api/admin/access/audit?page=2')->assertOk()->assertJsonCount(0, 'events');
        foreach ([false, true] as $all) {
            $this->postJson('/api/admin/procurement/reports', ['type' => 'audit', 'all' => $all])->assertOk()->assertJsonPath('totals.rows', 1)->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.actor', $employee['username']);
        }
        $this->postJson('/api/admin/procurement/reports', ['type' => 'audit', 'filters' => ['actor' => 'owner'], 'all' => true])->assertOk()->assertJsonCount(0, 'rows');
        $this->owner();
        $this->getJson('/api/admin/access/audit?page=2')->assertOk()->assertJsonCount(6, 'events');
        $this->postJson('/api/admin/procurement/reports', ['type' => 'audit', 'all' => true])->assertOk()->assertJsonPath('totals.rows', 2);
        $this->assertSame(55, DB::table('admin_access_audit')->where('actor_id', 'owner')->count());
        $this->assertSame(1, DB::table('procurement_audit')->where('actor', 'owner')->count());
    }

    public function test_business_record_actor_is_anonymized_without_changing_stored_records(): void
    {
        $employee = $this->employee([], true);
        $payload = ['rows' => [['actor' => 'owner', 'quantity' => 10, 'details' => '{"actor":"owner","quantity":10}'], ['actor' => $employee['username']]]];
        $this->asEmployee($employee);
        $this->getJson('/api/admin/session')->assertOk();
        $staffRequest = Request::create('/api/admin/procurement/reports');
        $staffRequest->attributes->set('adminAccess', app(AdminAccess::class)->resolveSession((object) ['staff_id' => $employee['id']]));
        $this->app->instance('request', $staffRequest);
        $filtered = app(AdminPolicy::class)->filter($payload, 'api/admin/procurement/reports');
        $this->assertSame('النظام', $filtered['rows'][0]['actor']);
        $this->assertSame('النظام', json_decode($filtered['rows'][0]['details'], true)['actor']);
        $this->assertSame(10, $filtered['rows'][0]['quantity']);
        $this->assertSame($employee['username'], $filtered['rows'][1]['actor']);
        $staffRequest->attributes->set('adminAccess', ['owner' => true, 'permissions' => AdminAccess::keys()]);
        $this->assertSame($payload, app(AdminPolicy::class)->filter($payload, 'api/admin/procurement/reports'));
    }
}
