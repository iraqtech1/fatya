<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AdminAccess
{
    public static function catalog(): array
    {
        return json_decode(file_get_contents(resource_path('admin-permissions.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function keys(): array
    {
        $keys = [];
        foreach (self::catalog() as $group) {
            foreach ($group['actions'] as $action => $label) {
                $keys[] = $group['id'].'.'.$action;
            }
        }

        return $keys;
    }

    public function resolveSession(object $session): array
    {
        if (! ($session->staff_id ?? null)) {
            $profile = DB::table('admin_owner_profile')->where('id', 1)->first();

            return ['id' => 'owner', 'name' => $profile->name ?? 'مالك النظام', 'username' => (string) config('fatya.admin_user'), 'owner' => true, 'unrestricted' => true, 'roleId' => null, 'roleName' => 'مالك النظام', 'permissions' => self::keys()];
        }
        $staff = DB::table('admin_staff')->where('id', $session->staff_id)->whereNull('deleted_at')->where('active', true)->first();
        $role = $staff ? DB::table('admin_roles')->where('id', $staff->role_id)->first() : null;
        if (! $staff || ! $role) {
            throw new ApiError('حساب الموظف موقوف أو غير متاح', 401);
        }

        return ['id' => $staff->id, 'name' => $staff->name, 'username' => $staff->username, 'owner' => false, 'unrestricted' => (bool) $role->unrestricted, 'roleId' => $role->id, 'roleName' => $role->name, 'permissions' => $role->unrestricted ? self::keys() : array_values(array_intersect(self::keys(), json_decode($role->permissions, true)))];
    }

    public function current(?Request $request = null): array
    {
        $request ??= request();
        if (! $request->attributes->has('adminAccess')) {
            app(Accounts::class)->session($request, 'admin');
        }

        return $request->attributes->get('adminAccess');
    }

    public function can(string $permission, ?Request $request = null): bool
    {
        return in_array($permission, $this->current($request)['permissions'], true);
    }

    public function require(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            if (! $this->can($permission)) {
                throw new ApiError('لا تملك صلاحية تنفيذ هذه العملية: '.$permission, 403);
            }
        }
    }

    public function login(array $body)
    {
        $username = is_string($body['username'] ?? null) ? strtolower(trim($body['username'])) : '';
        $password = $body['password'] ?? null;
        if (! is_string($password) || strlen($password) > 512 || strlen($username) > 254) {
            throw new ApiError('اسم المستخدم أو كلمة المرور غير صحيح', 401);
        }
        $key = 'admin-username:'.hash('sha256', $username);
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw new ApiError('انتظر دقيقة قبل إعادة محاولة الدخول', 429);
        }
        RateLimiter::hit($key, 60);
        $ownerPassword = config('fatya.admin_password');
        $profile = DB::table('admin_owner_profile')->where('id', 1)->first();
        $owner = $username === strtolower((string) config('fatya.admin_user')) || ($profile->email && $username === $profile->email);
        $ownerValid = $profile->password ? Hash::check($password, $profile->password) : is_string($ownerPassword) && strlen($ownerPassword) >= 12 && hash_equals(hash('sha256', $ownerPassword), hash('sha256', $password));
        if ($owner && $ownerValid) {
            RateLimiter::clear($key);
            request()->attributes->set('adminAccess', ['id' => 'owner', 'username' => (string) config('fatya.admin_user')]);
            $this->audit('login', 'owner');

            return app(Accounts::class)->issue('admin');
        }
        $staff = $owner ? null : DB::table('admin_staff')->where(fn ($q) => $q->where('username', $username)->orWhere('email', $username))->whereNull('deleted_at')->first();
        $valid = Hash::check($password, $staff->password ?? Hash::make('missing-account-dummy-password'));
        if (! $staff || ! $valid || ! $staff->active) {
            throw new ApiError('اسم المستخدم أو كلمة المرور غير صحيح', 401);
        }
        if (Hash::needsRehash($staff->password)) {
            DB::table('admin_staff')->where('id', $staff->id)->update(['password' => Hash::make($password)]);
        }
        DB::table('admin_staff')->where('id', $staff->id)->update(['last_login_at' => now()]);
        RateLimiter::clear($key);
        request()->attributes->set('adminAccess', ['id' => $staff->id, 'username' => $staff->username]);
        $this->audit('login', $staff->id);

        return app(Accounts::class)->issue('admin', null, $staff->id);
    }

    public function confirmPassword(mixed $password): bool
    {
        if (! is_string($password) || strlen($password) > 512) {
            return false;
        }
        $actor = $this->current();
        if ($actor['owner']) {
            $hash = DB::table('admin_owner_profile')->where('id', 1)->value('password');
            if ($hash) {
                return Hash::check($password, $hash);
            }
            $expected = config('fatya.admin_password');

            return is_string($expected) && strlen($expected) >= 12 && hash_equals(hash('sha256', $expected), hash('sha256', $password));
        }
        $hash = DB::table('admin_staff')->where('id', $actor['id'])->where('active', true)->whereNull('deleted_at')->value('password');

        return is_string($hash) && Hash::check($password, $hash);
    }

    public function audit(string $action, ?string $subject, array $details = []): void
    {
        $actor = request()->attributes->get('adminAccess');
        DB::table('admin_access_audit')->insert(['actor_id' => $actor['id'] ?? 'owner', 'actor' => $actor['username'] ?? (string) config('fatya.admin_user'), 'action' => $action, 'subject' => $subject, 'details' => Store::json($details), 'created_at' => now()]);
    }

    private function role(object $role): array
    {
        return ['id' => $role->id, 'name' => $role->name, 'description' => $role->description, 'unrestricted' => (bool) $role->unrestricted, 'permissions' => json_decode($role->permissions, true), 'version' => (int) $role->version];
    }

    private function staff(object $staff): array
    {
        return ['id' => $staff->id, 'name' => $staff->name, 'username' => $staff->username, 'email' => $staff->email ?? '', 'about' => $staff->about ?? '', 'image' => $staff->image ?? '', 'roleId' => $staff->role_id, 'active' => (bool) $staff->active, 'version' => (int) $staff->version, 'lastLoginAt' => $staff->last_login_at, 'createdAt' => $staff->created_at];
    }

    public function roles(): array
    {
        $counts = DB::table('admin_staff')->whereNull('deleted_at')->selectRaw('role_id, COUNT(*) AS total')->groupBy('role_id')->pluck('total', 'role_id');

        return DB::table('admin_roles')->orderBy('name')->get()->map(fn ($r) => [...$this->role($r), 'staffCount' => (int) ($counts[$r->id] ?? 0)])->all();
    }

    public function staffList(): array
    {
        return DB::table('admin_staff')->whereNull('deleted_at')->orderBy('name')->get()->map(fn ($r) => $this->staff($r))->all();
    }

    private function version(object $row, array $body): void
    {
        if (($body['version'] ?? null) !== (int) $row->version) {
            throw new ApiError('السجل تغيّر؛ أعد تحميله قبل الحفظ', 409);
        }
    }

    public function mayGrant(object $role): void
    {
        $actor = $this->current();
        if ($actor['owner']) {
            return;
        }
        if ($role->unrestricted || array_diff(json_decode($role->permissions, true), $actor['permissions'])) {
            throw new ApiError('لا يمكنك منح أو إدارة صلاحيات أعلى من صلاحياتك؛ الصلاحية المطلقة يديرها مالك النظام', 403);
        }
    }

    private function password(mixed $password): string
    {
        if (! is_string($password) || mb_strlen($password) < 12 || mb_strlen($password) > 128) {
            throw new ApiError('كلمة مرور الموظف من 12 إلى 128 حرفاً', 422);
        }

        return Hash::make($password);
    }

    public function saveRole(array $body): array
    {
        return DB::transaction(function () use ($body) {
            $old = isset($body['id']) ? DB::table('admin_roles')->where('id', $body['id'])->lockForUpdate()->first() : null;
            if (isset($body['id']) && ! $old) {
                throw new ApiError('الدور غير موجود', 404);
            }
            $this->require($old ? 'roles.update' : 'roles.create');
            if ($old) {
                $this->version($old, $body);
                $this->mayGrant($old);
            }
            $name = CatalogEditor::text($body['name'] ?? null, 80);
            $description = CatalogEditor::text($body['description'] ?? '', 500);
            $permissions = $body['permissions'] ?? null;
            if (! $name || ! is_bool($body['unrestricted'] ?? null) || ! is_array($permissions) || ! array_is_list($permissions) || count($permissions) > count(self::keys()) || count(array_filter($permissions, 'is_string')) !== count($permissions) || array_diff($permissions, self::keys())) {
                throw new ApiError('اختر اسماً وصلاحيات صحيحة للدور', 422);
            }
            $role = (object) ['unrestricted' => $body['unrestricted'], 'permissions' => Store::json(array_values(array_unique($permissions)))];
            $this->mayGrant($role);
            if (DB::table('admin_roles')->where('name', $name)->when($old, fn ($q) => $q->where('id', '!=', $old->id))->exists()) {
                throw new ApiError('اسم الدور مستخدم', 409);
            }
            $id = $old->id ?? (string) Str::uuid();
            $data = ['name' => $name, 'description' => $description, 'unrestricted' => $role->unrestricted, 'permissions' => $role->permissions, 'version' => ($old->version ?? 0) + 1, 'updated_at' => now()];
            if ($old) {
                DB::table('admin_roles')->where('id', $id)->update($data);
                DB::table('auth_tokens')->whereIn('staff_id', DB::table('admin_staff')->select('id')->where('role_id', $id))->delete();
            } else {
                DB::table('admin_roles')->insert(['id' => $id, 'created_at' => now(), ...$data]);
            }
            $this->audit($old ? 'role.updated' : 'role.created', $id, ['name' => $name, 'unrestricted' => $role->unrestricted, 'permissions' => $permissions]);

            return $this->role(DB::table('admin_roles')->where('id', $id)->first());
        });
    }

    public function deleteRole(array $body): void
    {
        $this->require('roles.delete');
        DB::transaction(function () use ($body) {
            $role = DB::table('admin_roles')->where('id', $body['id'] ?? '')->lockForUpdate()->first();
            if (! $role) {
                throw new ApiError('الدور غير موجود', 404);
            }
            $this->version($role, $body);
            $this->mayGrant($role);
            if (DB::table('admin_staff')->where('role_id', $role->id)->exists()) {
                throw new ApiError('الدور مرتبط بموظفين؛ غيّر أدوارهم أولاً', 409);
            }
            DB::table('admin_roles')->where('id', $role->id)->delete();
            $this->audit('role.deleted', $role->id, ['name' => $role->name]);
        });
    }

    public function saveStaff(array $body): array
    {
        return DB::transaction(function () use ($body) {
            $ownerProfile = DB::table('admin_owner_profile')->where('id', 1)->lockForUpdate()->first();
            // Same lock order as role editing/deletion avoids assignment racing role removal.
            $role = DB::table('admin_roles')->where('id', $body['roleId'] ?? '')->lockForUpdate()->first();
            if (! $role) {
                throw new ApiError('اختر دوراً موجوداً', 422);
            }
            $old = isset($body['id']) ? DB::table('admin_staff')->where('id', $body['id'])->whereNull('deleted_at')->lockForUpdate()->first() : null;
            if (isset($body['id']) && ! $old) {
                throw new ApiError('الموظف غير موجود', 404);
            }
            if (! $old) {
                $this->require('staff.create', 'staff.assign');
            } else {
                $this->version($old, $body);
                if ($old->id === $this->current()['id']) {
                    throw new ApiError('اطلب من مدير آخر تعديل حسابك لحماية صلاحية الدخول', 403);
                }
                $this->mayGrant(DB::table('admin_roles')->where('id', $old->role_id)->first());
            }
            $this->mayGrant($role);
            $name = CatalogEditor::text($body['name'] ?? null, 80);
            $username = strtolower(CatalogEditor::text($body['username'] ?? null, 64));
            $email = strtolower(CatalogEditor::text($body['email'] ?? $old->email ?? '', 254));
            $about = CatalogEditor::text($body['about'] ?? $old->about ?? '', 2000);
            $image = $body['image'] ?? $old->image ?? '';
            if ($email !== '' && (! filter_var($email, FILTER_VALIDATE_EMAIL) || $email === strtolower((string) config('fatya.admin_user')) || $email === $ownerProfile->email)) {
                throw new ApiError('البريد الإلكتروني غير صحيح أو محجوز', 422);
            }
            if ($email !== '' && $this->staffLoginExists($email, $old?->id)) {
                throw new ApiError('البريد الإلكتروني مستخدم', 409);
            }
            $this->profileImage($image);
            if (! $name) {
                throw new ApiError('اكتب اسم المدير؛ يمكن كتابته بالعربية أو الإنكليزية', 422);
            }
            if (! preg_match('/^[a-z0-9][a-z0-9_.-]{2,63}$/D', $username) && ! filter_var($username, FILTER_VALIDATE_EMAIL)) {
                throw new ApiError('اسم المستخدم من 3 إلى 64 خانة؛ اكتب يوزراً إنكليزياً مثل fatya.admin أو بريداً إلكترونياً صحيحاً', 422);
            }
            if ($username === strtolower((string) config('fatya.admin_user')) || $username === strtolower((string) $ownerProfile->email)) {
                throw new ApiError('اسم المستخدم محجوز لحساب مالك النظام؛ اختر يوزراً آخر', 422);
            }
            if (! is_bool($body['active'] ?? null)) {
                throw new ApiError('اختر حالة الحساب: مفعّل أو موقوف', 422);
            }
            if ($this->staffLoginExists($username, $old?->id)) {
                throw new ApiError('اسم المستخدم أو البريد الإلكتروني مستخدم لحساب آخر', 409);
            }
            if ($old) {
                if ($old->name !== $name || $old->username !== $username || ($old->about ?? '') !== $about || ($old->image ?? '') !== $image) {
                    $this->require('staff.update');
                }
                if (($old->email ?? '') !== $email) {
                    $this->require('staff.update', 'staff.password');
                }
                if ($old->role_id !== $role->id) {
                    $this->require('staff.assign');
                }
                if ((bool) $old->active !== $body['active']) {
                    $this->require('staff.status');
                }
                if (! empty($body['password'])) {
                    $this->require('staff.password');
                }
            }
            $id = $old->id ?? (string) Str::uuid();
            $data = ['name' => $name, 'username' => $username, 'email' => $email ?: null, 'about' => $about, 'image' => $image ?: null, 'role_id' => $role->id, 'active' => $body['active'], 'version' => ($old->version ?? 0) + 1, 'updated_at' => now()];
            if (! $old || ! empty($body['password'])) {
                $data['password'] = $this->password($body['password'] ?? null);
            }
            if ($old) {
                DB::table('admin_staff')->where('id', $id)->update($data);
                DB::table('auth_tokens')->where('staff_id', $id)->delete();
            } else {
                DB::table('admin_staff')->insert(['id' => $id, 'created_at' => now(), ...$data]);
            }
            $this->audit($old ? 'staff.updated' : 'staff.created', $id, ['username' => $username, 'roleId' => $role->id, 'active' => $body['active'], 'passwordChanged' => isset($data['password'])]);

            return $this->staff(DB::table('admin_staff')->where('id', $id)->first());
        });
    }

    private function staffLoginExists(string $value, ?string $exceptId = null): bool
    {
        return DB::table('admin_staff')->where(fn ($q) => $q->where('username', $value)->orWhere('email', $value))->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    private function profileImage(mixed $image): void
    {
        if ($image === '') {
            return;
        }
        if (! is_string($image) || strlen($image) > 512000 || ! preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~D', $image, $matches)) {
            throw new ApiError('اختر صورة PNG أو JPEG أو WebP صغيرة للمدير', 422);
        }
        $bytes = base64_decode($matches[2], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (! $info || ($info['mime'] ?? '') !== 'image/'.$matches[1] || $info[0] > 1024 || $info[1] > 1024) {
            throw new ApiError('صورة المدير غير صالحة أو أبعادها أكبر من 1024 بكسل', 422);
        }
    }

    public function myProfile(): array
    {
        $actor = $this->current();
        $row = $actor['owner'] ? DB::table('admin_owner_profile')->where('id', 1)->first() : DB::table('admin_staff')->where('id', $actor['id'])->first();

        return ['name' => $row->name, 'username' => $actor['username'], 'email' => $row->email ?? '', 'about' => $row->about ?? '', 'image' => $row->image ?? '', 'version' => (int) $row->version];
    }

    public function saveMyProfile(array $body): bool
    {
        return DB::transaction(function () use ($body) {
            $actor = $this->current();
            $owner = DB::table('admin_owner_profile')->where('id', 1)->lockForUpdate()->first();
            $old = $actor['owner'] ? $owner : DB::table('admin_staff')->where('id', $actor['id'])->whereNull('deleted_at')->where('active', true)->lockForUpdate()->first();
            if (! $old) {
                throw new ApiError('الحساب غير متاح', 401);
            }
            $this->version($old, $body);
            $name = CatalogEditor::text($body['name'] ?? $old->name, 80);
            $email = strtolower(CatalogEditor::text($body['email'] ?? $old->email ?? '', 254));
            $about = CatalogEditor::text($body['about'] ?? $old->about, 2000);
            $image = $body['image'] ?? $old->image ?? '';
            if (! $name || ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new ApiError('الاسم أو البريد الإلكتروني غير صحيح', 422);
            }
            $this->profileImage($image);
            if (isset($body['password']) && ! is_string($body['password'])) {
                throw new ApiError('كلمة المرور غير صحيحة', 422);
            }
            $passwordChanged = ($body['password'] ?? '') !== '';
            $emailChanged = $email !== ($old->email ?? '');
            if ($name !== $old->name || $about !== $old->about || $image !== ($old->image ?? '')) {
                $this->require('my_account.update');
            }
            if ($emailChanged || $passwordChanged) {
                $this->require('my_account.password');
                if (! $this->confirmPassword($body['currentPassword'] ?? null)) {
                    throw new ApiError('أدخل كلمة مرورك الحالية لتغيير البريد أو كلمة المرور', 403);
                }
            }
            if ($email !== '' && ($this->staffLoginExists($email, $actor['owner'] ? null : $old->id) || (! $actor['owner'] && ($email === $owner->email || $email === strtolower((string) config('fatya.admin_user')))))) {
                throw new ApiError('البريد الإلكتروني مستخدم أو محجوز', 409);
            }
            $data = ['name' => $name, 'email' => $email ?: null, 'about' => $about, 'image' => $image ?: null, 'version' => $old->version + 1, 'updated_at' => now()];
            if ($passwordChanged) {
                $data['password'] = $this->password($body['password']);
            }
            DB::table($actor['owner'] ? 'admin_owner_profile' : 'admin_staff')->where('id', $old->id)->update($data);
            if ($passwordChanged || $emailChanged) {
                DB::table('auth_tokens')->where('role', 'admin')->when($actor['owner'], fn ($q) => $q->whereNull('staff_id'), fn ($q) => $q->where('staff_id', $old->id))->delete();
            }
            $this->audit('account.updated', $actor['id'], ['passwordChanged' => $passwordChanged, 'emailChanged' => $emailChanged]);

            return $passwordChanged || $emailChanged;
        });
    }

    public function staffAction(string $action, array $body): void
    {
        $this->require('staff.'.$action);
        DB::transaction(function () use ($action, $body) {
            $staff = DB::table('admin_staff')->where('id', $body['id'] ?? '')->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $staff) {
                throw new ApiError('الموظف غير موجود', 404);
            }
            $this->version($staff, $body);
            if ($staff->id === $this->current()['id']) {
                throw new ApiError('لا يمكنك تعطيل أو تعديل جلسات حسابك من هذه الشاشة', 403);
            }
            $this->mayGrant(DB::table('admin_roles')->where('id', $staff->role_id)->first());
            $data = ['version' => $staff->version + 1, 'updated_at' => now()];
            if ($action === 'delete') {
                $data += ['deleted_at' => now(), 'active' => false];
            }
            DB::table('admin_staff')->where('id', $staff->id)->update($data);
            DB::table('auth_tokens')->where('staff_id', $staff->id)->delete();
            $this->audit('staff.'.$action, $staff->id);
        });
    }
}
