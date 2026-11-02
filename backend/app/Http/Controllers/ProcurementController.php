<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalConnections;
use App\Services\DigitalProviders;
use App\Services\DigitalSales;
use App\Services\ImportCategories;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ProcurementController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        app(Accounts::class)->session($request, 'admin');
        $b = $request->json()->all();
        $providers = app(DigitalProviders::class);
        $stock = app(StockOrders::class);
        // Provider network calls occur outside StoreController's database-wide transaction.
        if (in_array($path, ['digital/catalog', 'digital/balance', 'digital/provinces', 'digital/eligibility', 'digital/reconcile'], true)) {
            $key = 'procurement:'.$path.':'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 12)) {
                throw new ApiError('انتظر دقيقة قبل إعادة الاتصال بالشركة', 429);
            }
            RateLimiter::hit($key, 60);
        }
        $data = match ([$request->method(), $path]) {
            ['GET', 'stock-orders'] => ['batches' => $stock->batches(), 'submissions' => app(StockWorkflow::class)->submissions(), 'policy' => app(StockWorkflow::class)->policy(), ...app(ImportCategories::class)->read()],
            ['PUT', 'stock-orders/policy'] => app(StockWorkflow::class)->savePolicy($b),
            ['PUT', 'stock-orders/codes'] => app(ImportCategories::class)->save($b),
            ['POST', 'stock-orders/submit'] => app(StockWorkflow::class)->submit($b),
            ['POST', 'stock-orders/review'] => app(StockWorkflow::class)->review($b),
            ['POST', 'stock-orders/preview'] => $stock->preview($b),
            ['POST', 'stock-orders/approve'] => $stock->approve($b),
            ['GET', 'digital'] => ['connections' => $providers->status(), 'offers' => $providers->offers(), 'executions' => app(DigitalSales::class)->executions(), 'purchaseEnabled' => (bool) config('digital.purchases_enabled')],
            ['PUT', 'digital/connections'] => app(DigitalConnections::class)->save($b),
            ['POST', 'digital/reconcile'] => app(DigitalSales::class)->reconcile($b),
            ['POST', 'digital/catalog'] => $providers->catalog((string) ($b['provider'] ?? ''), $b['provinceId'] ?? null),
            ['GET', 'digital/balance'] => $providers->balance(),
            ['POST', 'digital/provinces'] => ['provinces' => $providers->provinces(($b['premium'] ?? false) === true)],
            ['POST', 'digital/eligibility'] => $providers->eligibility($b),
            ['PUT', 'digital/offers'] => $providers->saveOffer($b),
            default => throw new ApiError('غير موجود', 404),
        };

        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
