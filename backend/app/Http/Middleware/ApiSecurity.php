<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiError;
use Closure;
use Illuminate\Http\Request;

class ApiSecurity
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->method(), ['GET', 'HEAD'])) {
            $origin = $request->header('Origin');
            if ($origin && $origin !== $request->getSchemeAndHttpHost()) {
                throw new ApiError('طلب غير مسموح', 403);
            }
            if (! $request->isJson()) {
                throw new ApiError('صيغة غير صحيحة', 415);
            }
            if (strlen($request->getContent()) > 30 * 1024 * 1024) {
                throw new ApiError('حجم البيانات كبير', 413);
            }
            try {
                $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new ApiError('بيانات غير صحيحة');
            }
            if (! is_object($body)) {
                throw new ApiError('بيانات غير صحيحة');
            }
            // JSON-only writes and this non-simple header prevent cross-origin form submissions.
            if ($request->header('X-Fatya-Request') !== '1') {
                throw new ApiError('طلب غير مسموح', 403);
            }
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
