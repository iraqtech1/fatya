<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\DigitalConnections;
use App\Services\DigitalManualPayments;
use App\Services\DigitalProviders;
use App\Services\DigitalPublications;
use App\Services\DigitalSales;
use App\Services\ImportCategories;
use App\Services\ImportFileReader;
use App\Services\InventoryExports;
use App\Services\InventoryReports;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class ProcurementController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        app(Accounts::class)->session($request, 'admin');
        if (in_array($path, ['digital/manual-payment', 'exports', 'exports/review', 'exports/renew', 'exports/download'], true) && ! $request->isMethod('GET')) {
            $key = 'private-exports:'.$path.':'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 12)) {
                throw new ApiError('انتظر دقيقة قبل إعادة محاولة تصدير الأكواد', 429);
            }
            RateLimiter::hit($key, 60);
        }
        if ($path === 'stock-orders/read' && $request->isMethod('POST')) {
            if (! $request->hasFile('file')) {
                throw new ApiError('اختر ملفاً صالحاً؛ تحقق من حدود رفع الملفات بالخادم', 422);
            }

            return response()->json(['sheets' => app(ImportFileReader::class)->read($request->file('file'))])->header('Cache-Control', 'no-store');
        }
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
            ['POST', 'reports'] => app(InventoryReports::class)->read($b),
            ['GET', 'exports'] => app(InventoryExports::class)->listing(),
            ['POST', 'exports'] => app(InventoryExports::class)->create($b),
            ['POST', 'exports/review'] => app(InventoryExports::class)->review($b),
            ['POST', 'exports/download'] => app(InventoryExports::class)->download($b),
            ['POST', 'exports/renew'] => app(InventoryExports::class)->renew($b),
            ['PUT', 'stock-orders/policy'] => app(StockWorkflow::class)->savePolicy($b),
            ['PUT', 'stock-orders/code'] => app(ImportCategories::class)->add($b),
            ['PUT', 'stock-orders/codes'] => app(ImportCategories::class)->save($b),
            ['POST', 'stock-orders/submit'] => $stock->approve($b),
            ['POST', 'stock-orders/review'] => app(StockWorkflow::class)->review($b),
            ['POST', 'stock-orders/preview'] => $stock->preview($b),
            ['POST', 'stock-orders/approve'] => $stock->approve($b),
            ['GET', 'digital'] => ['connections' => $providers->status(), 'offers' => $providers->offers(), 'catalogs' => app(DigitalPublications::class)->catalogs(), 'publications' => app(DigitalPublications::class)->listing(), 'executions' => app(DigitalSales::class)->executions(), 'purchaseEnabled' => (bool) config('digital.purchases_enabled')],
            ['PUT', 'digital/publications'] => app(DigitalPublications::class)->save($b),
            ['PUT', 'digital/publications/bulk'] => app(DigitalPublications::class)->bulk($b),
            ['POST', 'digital/manual-payment'] => app(DigitalManualPayments::class)->confirm($b),
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
