<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\Accounts;
use App\Services\AdminAccess;
use App\Services\ApplicationDisplay;
use App\Services\CardLifecycle;
use App\Services\CatalogSources;
use App\Services\CompanyHierarchy;
use App\Services\DigitalConnections;
use App\Services\DigitalHistory;
use App\Services\DigitalManualPayments;
use App\Services\DigitalProviders;
use App\Services\DigitalPublications;
use App\Services\DigitalRefunds;
use App\Services\DigitalSales;
use App\Services\ImportCategories;
use App\Services\ImportFileReader;
use App\Services\InventoryExports;
use App\Services\InventoryReports;
use App\Services\NotificationCampaigns;
use App\Services\PriceCenter;
use App\Services\ProcurementAudit;
use App\Services\RabiaaActivations;
use App\Services\StockOrderCatalog;
use App\Services\StockOrders;
use App\Services\StockWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class ProcurementController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        app(Accounts::class)->session($request, 'admin');
        if (in_array($path, ['digital/receipt', 'digital/refund-settle', 'digital/manual-payment', 'exports', 'exports/review', 'exports/renew', 'exports/download', 'lookup'], true) && ! $request->isMethod('GET')) {
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
        if (in_array($path, ['digital/catalog', 'digital/balance', 'digital/provinces', 'digital/eligibility', 'digital/reconcile', 'digital/refund-check', 'digital/activations'], true)) {
            $key = 'procurement:'.$path.':'.$request->ip();
            if (RateLimiter::tooManyAttempts($key, 12)) {
                throw new ApiError('انتظر دقيقة قبل إعادة الاتصال بالشركة', 429);
            }
            RateLimiter::hit($key, 60);
        }
        if ($path === 'digital/activations' && $request->isMethod('POST') && isset($b['order_id']) && ! app(AdminAccess::class)->can('inventory.codes')) {
            throw new ApiError('تحتاج صلاحية كشف الأكواد لاستعمال بطاقة محفوظة', 403);
        }
        $activationActor = 'admin:'.app(AdminAccess::class)->current()['id'];
        $data = match ([$request->method(), $path]) {
            ['GET', 'application-display'] => app(ApplicationDisplay::class)->preview(),
            ['PUT', 'company-order'] => app(CompanyHierarchy::class)->move($b),
            ['POST', 'refunds/settle'] => app(DigitalRefunds::class)->settle($b, true),
            ['PUT', 'application-display'] => app(ApplicationDisplay::class)->save($b),
            ['GET', 'notifications'] => app(NotificationCampaigns::class)->listing(),
            ['POST', 'notifications/save'] => app(NotificationCampaigns::class)->save($b),
            ['POST', 'notifications/review'] => app(NotificationCampaigns::class)->transition($b, false),
            ['POST', 'notifications/send'] => app(NotificationCampaigns::class)->transition($b, true),
            ['POST', 'notifications/rules'] => app(NotificationCampaigns::class)->saveRules($b),
            ['GET', 'prices'] => app(PriceCenter::class)->listing(),
            ['POST', 'prices/preview'] => app(PriceCenter::class)->preview($b),
            ['POST', 'prices/apply'] => app(PriceCenter::class)->apply($b),
            ['GET', 'stock-orders'] => ['apiDenominations' => StockOrderCatalog::apiDenominations(), 'sources' => CatalogSources::storedOptions(), 'batches' => $stock->batches(), 'submissions' => app(StockWorkflow::class)->submissions(), 'policy' => app(StockWorkflow::class)->policy(), ...app(ImportCategories::class)->read()],
            ['POST', 'lookup'] => app(CardLifecycle::class)->read($b),
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
            ['POST', 'digital/history'] => app(DigitalHistory::class)->read($b),
            ['POST', 'digital/rabiaa-history'] => app(DigitalHistory::class)->read($b, 'rabiaa'),
            ['POST', 'digital/topup-profit'] => app(DigitalHistory::class)->read($b, 'topup', true),
            ['GET', 'digital/activations'] => app(RabiaaActivations::class)->listing(null, (int) $request->query('page', 1), $request->query()),
            ['POST', 'digital/activations'] => ['data' => app(RabiaaActivations::class)->submit($activationActor, $b)],
            ['GET', 'digital'] => ['connections' => $providers->status(), 'offers' => $providers->offers(), 'catalogs' => app(DigitalPublications::class)->catalogs(), 'publications' => app(DigitalPublications::class)->listing(), 'executions' => app(DigitalSales::class)->executions(), 'purchaseEnabled' => (bool) config('digital.purchases_enabled')],
            ['PUT', 'digital/publications'] => app(DigitalPublications::class)->save($b),
            ['PUT', 'digital/publications/bulk'] => app(DigitalPublications::class)->bulk($b),
            ['POST', 'digital/manual-payment'] => app(DigitalManualPayments::class)->confirm($b),
            ['PUT', 'digital/connections'] => app(DigitalConnections::class)->save($b),
            ['POST', 'digital/receipt'] => $this->receipt($b),
            ['POST', 'digital/refund-check'] => app(DigitalSales::class)->reconcile([...$b, 'refundCheck' => true]),
            ['POST', 'digital/refund-settle'] => app(DigitalRefunds::class)->settle($b),
            ['POST', 'digital/reconcile'] => app(DigitalSales::class)->reconcile([...$b, 'refundCheck' => false]),
            ['POST', 'digital/catalog'] => $providers->catalog((string) ($b['provider'] ?? ''), $b['provinceId'] ?? null, true),
            ['POST', 'digital/catalog/accept'] => $providers->acceptCatalog($b),
            ['GET', 'digital/balance'] => $providers->balance(),
            ['POST', 'digital/provinces'] => ['provinces' => $providers->provinces(($b['premium'] ?? false) === true)],
            ['POST', 'digital/eligibility'] => $providers->eligibility($b),
            ['PUT', 'digital/offers'] => $providers->saveOffer($b),
            default => throw new ApiError('غير موجود', 404),
        };

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private function receipt(array $input): array
    {
        $id = $input['orderId'] ?? null;
        if (! is_string($id) || strlen($id) > 100 || ! DB::table('digital_executions')->where('order_id', $id)->where('provider', 'rabiaa')->exists()) {
            throw new ApiError('بطاقة الرابعة غير موجودة', 404);
        }
        $receipt = app(DigitalSales::class)->receipt($id, true);
        ProcurementAudit::record('digital.receipt_read', $id, ['state' => $receipt['state']]);

        return $receipt;
    }
}
