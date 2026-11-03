<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\AdminAccess;
use App\Services\AdminPolicy;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuthorization
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('api/admin/*') || $request->is('api/admin/login')) {
            return $next($request);
        }
        app(Accounts::class)->session($request, 'admin');
        $access = app(AdminAccess::class);
        try {
            app(AdminPolicy::class)->authorize($request);
            $response = $next($request);
        } catch (ApiError $e) {
            if ($e->status === 403) {
                $access->audit('permission.denied', null, ['method' => $request->method(), 'path' => $request->path()]);
            }
            throw $e;
        }
        if ($response->getStatusCode() === 403) {
            $access->audit('permission.denied', null, ['method' => $request->method(), 'path' => $request->path()]);
        }
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);
            $response->setData(app(AdminPolicy::class)->filter($data, $request->path()));
            if (! $request->isMethod('GET') && ! $request->is('api/admin/access/*') && ! $request->is('api/admin/logout') && ! $request->is('api/admin/procurement/reports') && ! $request->is('api/admin/cards/search')) {
                $access->audit('operation', null, ['method' => $request->method(), 'path' => $request->path()]);
            }
        }

        return $response;
    }
}
