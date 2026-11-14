<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\RabiaaActivations;
use App\Services\Store;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class RabiaaActivationController extends Controller
{
    public function __invoke(Request $request)
    {
        $customer = app(Accounts::class)->customer($request, app(Store::class)->read());
        $actor = 'customer:'.$customer['id'];
        if ($request->isMethod('GET')) {
            return response()->json(app(RabiaaActivations::class)->listing($actor, (int) $request->query('page', 1)))->header('Cache-Control', 'no-store');
        }
        if (RateLimiter::tooManyAttempts('activation:'.$actor, 6)) {
            throw new ApiError('انتظر دقيقة قبل إعادة المحاولة', 429);
        }
        RateLimiter::hit('activation:'.$actor, 60);

        return response()->json(['data' => app(RabiaaActivations::class)->submit($actor, $request->json()->all(), $customer['id'])])->header('Cache-Control', 'no-store');
    }
}
