<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StateValidators
{
    public function handle(Request $request, Closure $next)
    {
        // Authenticate and filter permission-dependent state BEFORE checking validators.
        $response = $next($request);
        if ($request->isMethod('GET') && in_array($request->path(), ['api/state', 'api/admin/state'])
            && $response instanceof JsonResponse && $response->getStatusCode() === 200) {
            // Hash the final representation, including stock expiry, notifications and roles,
            // rather than only the store revision (not all changes increment that revision).
            $response->setEtag(hash('sha256', $request->path()."\0".$response->getContent()));
            $response->setVary('Cookie', false);
            $response->isNotModified($request);
        }

        return $response;
    }
}
