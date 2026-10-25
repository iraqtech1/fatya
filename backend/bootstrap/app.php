<?php

use App\Exceptions\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: [fn ($request) => $request->is('api/*')]);
        $middleware->convertEmptyStringsToNull(except: [fn ($request) => $request->is('api/*')]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*'));
        $exceptions->render(function (ApiError $e) {
            return response()->json(['error' => $e->getMessage()], $e->status)->withHeaders(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json(['error' => $status === 404 ? 'غير موجود' : 'تعذّر تنفيذ الطلب'], $status)->withHeaders(['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    })->create();
