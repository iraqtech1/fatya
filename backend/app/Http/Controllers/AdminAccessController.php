<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\AdminAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAccessController extends Controller
{
    public function __invoke(Request $request, string $path, AdminAccess $access)
    {
        $b = $request->json()->all();
        if ($request->isMethod('POST') && $path === 'account/save') {
            $rotate = $access->saveMyProfile($b);
            $response = response()->json(['account' => $access->myProfile(), 'access' => $access->current()]);
            if ($rotate) {
                $actor = $access->current();
                $response->cookie(app(Accounts::class)->issue('admin', null, $actor['owner'] ? null : $actor['id']));
            }

            return $response;
        }
        $data = match ([$request->method(), $path]) {
            ['GET', 'account'] => ['account' => $access->myProfile()],
            ['GET', 'catalog'] => ['groups' => $access::catalog(), 'access' => $access->current()],
            ['GET', 'roles'] => ['roles' => $access->roles()],
            ['GET', 'role-options'] => ['roles' => array_values(array_map(function ($role) use ($access) {
                try {
                    $access->mayGrant((object) ['unrestricted' => $role['unrestricted'], 'permissions' => json_encode($role['permissions'])]);
                    $allowed = true;
                } catch (ApiError) {
                    $allowed = false;
                }

                return ['id' => $role['id'], 'name' => $role['name'], 'unrestricted' => $role['unrestricted'], 'canAssign' => $allowed];
            }, $access->roles()))],
            ['GET', 'staff'] => ['staff' => $access->staffList()],
            ['GET', 'audit'] => ['events' => DB::table('admin_access_audit')->when(! $access->current()['owner'], fn ($query) => $query->where('actor_id', '!=', 'owner')->where('actor', '!=', (string) config('fatya.admin_user')))->orderByDesc('id')->offset((max(1, min(100000, (int) $request->query('page', 1))) - 1) * 50)->limit(50)->get()->map(fn ($r) => ['id' => $r->id, 'actor' => $r->actor, 'action' => $r->action, 'subject' => $r->subject, 'details' => json_decode($r->details, true), 'date' => $r->created_at])->all()],
            ['POST', 'roles/save'] => ['role' => $access->saveRole($b)],
            ['POST', 'roles/delete'] => $this->run(fn () => $access->deleteRole($b)),
            ['POST', 'staff/save'] => ['staff' => $access->saveStaff($b)],
            ['POST', 'staff/revoke'] => $this->run(fn () => $access->staffAction('revoke', $b)),
            ['POST', 'staff/delete'] => $this->run(fn () => $access->staffAction('delete', $b)),
            default => throw new ApiError('غير موجود', 404),
        };

        return response()->json($data);
    }

    private function run(callable $callback): array
    {
        $callback();

        return ['ok' => true];
    }
}
