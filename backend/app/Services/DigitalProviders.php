<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Server-side, read-only provider workbench. Financial POSTs are deliberately not exposed. */
class DigitalProviders
{
    public function settings(string $provider): array
    {
        if (! in_array($provider, ['topup', 'rabiaa'], true)) {
            throw new ApiError('المزود غير موجود', 404);
        }
        $c = config('digital.'.$provider);
        if (! $c['enabled'] || ! $c['key']) {
            throw new ApiError('الربط غير مفعّل؛ أضف مفتاح الشركة في إعدادات الخادم الخاصة', 409);
        }
        $url = parse_url($c['base']);
        $hosts = ['topup' => 'masal-co.com', 'rabiaa' => 'tryapi.nojoomalrabiaa.com'];
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') !== $hosts[$provider] || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['port'])) {
            throw new ApiError('عنوان الشركة يحتاج اعتماداً في إعدادات الخادم', 409);
        }

        return $c;
    }

    private function read(string $provider, string $path, array $query = [], bool $eligibility = false): mixed
    {
        $c = $this->settings($provider);
        try {
            $http = Http::acceptJson()->withHeaders([$provider === 'topup' ? 'x-api-key' : 'X-API-Key' => $c['key']])->connectTimeout(8)->timeout(25)->withoutRedirecting();
            $url = rtrim($c['base'], '/').$path;
            $r = $eligibility ? $http->post($url, $query) : $http->get($url, $query);
            if (! $r->successful() || strlen($r->body()) > 4 * 1024 * 1024) {
                throw new \RuntimeException;
            }

            return json_decode($r->body(), true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            // Never echo provider responses, URLs with credentials, PINs or transport exceptions.
            throw new ApiError('تعذّر قراءة بيانات الشركة؛ تحقق من الحساب والاتصال', 502);
        }
    }

    public function status(): array
    {
        return array_map(function ($p) {
            $c = config('digital.'.$p);
            $snapshot = DB::table('digital_catalog')->where('connection', $p)->first();

            return ['id' => $p, 'name' => $p === 'topup' ? 'التوب أب' : 'الرابعة', 'configured' => (bool) ($c['enabled'] && $c['key']), 'lastSync' => $snapshot ? date(DATE_ATOM, $snapshot->synced) : null, 'balanceSupported' => $p === 'topup'];
        }, ['topup', 'rabiaa']);
    }

    public function balance(): array
    {
        $raw = $this->read('topup', '/api/v1/inventory');
        $value = is_array($raw) ? ($raw['remaining_balance'] ?? $raw['data']['remaining_balance'] ?? null) : $raw;
        try {
            $amount = StockOrders::money(is_scalar($value) ? (string) $value : null);
        } catch (ApiError) {
            throw new ApiError('رد رصيد الشركة غير مفهوم', 502);
        }

        return ['balance' => $amount, 'currency' => 'IQD', 'updatedAt' => now()->toIso8601String()];
    }

    public static function mobile(mixed $value): string
    {
        $phone = Accounts::phone($value);
        if (! preg_match('/^07[0-9]{9}$/D', $phone)) {
            throw new ApiError('رقم الهاتف العراقي غير صحيح', 422);
        }

        return '964'.substr($phone, 1);
    }

    public function provinces(bool $premium = false): array
    {
        $raw = $this->read('rabiaa', $premium ? '/vendor/bein/provinces' : '/vendor/provinces');
        $rows = $raw['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
            throw new ApiError('رد المحافظات غير مفهوم', 502);
        }

        return array_values(array_map(fn ($r) => ['id' => (string) ($r['id'] ?? ''), 'title' => CatalogEditor::text($r['title'] ?? '', 100)], $rows));
    }

    public function catalog(string $provider, mixed $province = null): array
    {
        if ($provider === 'rabiaa' && (! is_string($province) || ! preg_match('/^[0-9]{1,10}$/D', $province))) {
            throw new ApiError('اختر المحافظة أولاً', 422);
        }
        $raw = $this->read($provider, $provider === 'topup' ? '/api/v1/products' : '/vendor/catalog', $provider === 'topup' ? [] : ['provinceId' => $province]);
        $rows = $provider === 'topup' ? ($raw['products'] ?? null) : ($raw['data'] ?? null);
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 5000) {
            throw new ApiError('رد كتالوج الشركة غير مفهوم', 502);
        }
        $offers = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $provider === 'topup' ? ($row['id'] ?? $row['product_id'] ?? null) : ($row['catalogId'] ?? null);
            if (! is_scalar($id) || ! preg_match('/^[0-9]{1,100}$/D', (string) $id) || ($provider === 'topup' && (string) ($row['show2site'] ?? '0') !== '1')) {
                continue;
            }
            $type = $provider === 'topup' ? ($row['type'] ?? '') : ($row['packageType'] ?? '');
            if (! in_array($type, $provider === 'topup' ? ['topup', 'bundle', 'bill'] : ['standard', 'premium', 'Premium'], true)) {
                continue;
            }
            try {
                $cost = StockOrders::money((string) ($provider === 'topup' ? ($row['price'] ?? '') : ($row['vendorPrice'] ?? '')));
            } catch (ApiError) {
                $cost = null;
            }
            $available = $cost !== null && BigDecimal::of($cost)->isGreaterThan(0) && ($provider === 'topup' || ($row['inStock'] ?? null) === true);
            $offers[] = ['providerId' => (string) $id, 'title' => CatalogEditor::text($row['title'] ?? '', 200), 'type' => strtolower($type), 'parentId' => isset($row['parent_id']) ? (string) $row['parent_id'] : null, 'cost' => $cost, 'costBasis' => 'catalog_snapshot', 'available' => $available, 'provinceId' => $provider === 'rabiaa' ? $province : null];
        }
        $snapshot = ['provider' => $provider, 'offers' => $offers, 'provinceId' => $province, 'syncedAt' => now()->toIso8601String()];
        $snapshot['hash'] = hash('sha256', Store::json($snapshot));
        DB::table('digital_catalog')->updateOrInsert(['connection' => $provider], ['snapshot' => Store::json($snapshot), 'synced' => time()]);

        return $snapshot;
    }

    public function eligibility(array $b): array
    {
        $mobile = self::mobile($b['mobile'] ?? null);
        if ($mobile !== self::mobile($b['confirmMobile'] ?? null)) {
            throw new ApiError('رقما الهاتف غير متطابقين', 422);
        }
        // Re-read current product price and availability instead of trusting fields from the browser.
        $catalog = $this->catalog('topup');
        $offer = collect($catalog['offers'])->firstWhere('providerId', (string) ($b['providerId'] ?? ''));
        if (! $offer || ! $offer['available']) {
            throw new ApiError('الخدمة غير متوفرة أو سعرها غير محدد', 409);
        }
        $raw = $this->read('topup', '/api/v1/checkEligibility', ['mobile' => $mobile, 'type' => $offer['type'], 'category' => $offer['providerId']], true);

        return ['eligible' => is_array($raw) && ($raw['status'] ?? null) === true, 'mobile' => $mobile, 'providerId' => $offer['providerId'], 'cost' => $offer['cost'], 'checkedAt' => now()->toIso8601String()];
    }

    public function offers(): array
    {
        return DB::table('digital_offers')->orderByDesc('updated')->get()->map(fn ($r) => ['id' => $r->id, 'productId' => $r->product_id, 'denom' => (int) $r->denom_index, 'provider' => $r->connection, 'providerId' => $r->provider_id, 'retail' => StockOrders::money((string) $r->retail), 'active' => (bool) $r->active, 'snapshot' => json_decode($r->snapshot, true)])->all();
    }

    public function saveOffer(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $s = app(Store::class)->read(true);
            if (($b['revision'] ?? null) !== $s['revision']) {
                throw new ApiError('البيانات تغيرت حدّث الصفحة', 409);
            }
            $p = collect($s['products'])->first(fn ($r) => (string) $r['id'] === (string) ($b['productId'] ?? ''));
            $denom = $b['denom'] ?? null;
            if (! $p || ! is_int($denom) || ! isset($p['values'][$denom]) || ! is_bool($b['active'] ?? null)) {
                throw new ApiError('الفئة المحلية غير صحيحة', 422);
            }
            $retail = StockOrders::money($b['retail'] ?? null);
            if (! BigDecimal::of($retail)->isGreaterThan(0)) {
                throw new ApiError('سعر البيع يجب أن يكون أكبر من صفر', 422);
            }
            $provider = $b['provider'] ?? '';
            if (! in_array($provider, ['topup', 'rabiaa'], true)) {
                throw new ApiError('المزود غير صحيح', 422);
            }
            $catalog = DB::table('digital_catalog')->where('connection', $provider)->first();
            $snapshot = $catalog ? json_decode($catalog->snapshot, true) : null;
            if (! $snapshot || ($b['catalogHash'] ?? null) !== $snapshot['hash']) {
                throw new ApiError('كتالوج الشركة تغير؛ اجلب الفئات من جديد', 409);
            }
            $offer = collect($snapshot['offers'])->firstWhere('providerId', (string) ($b['providerId'] ?? ''));
            if (! $offer || ($b['active'] && (! $offer['available'] || $catalog->synced < time() - 900))) {
                throw new ApiError('حدّث كتالوج الشركة واختر فئة متوفرة', 409);
            }
            $old = DB::table('digital_offers')->where('product_id', (string) $p['id'])->where('denom_index', $denom)->first();
            DB::table('digital_offers')->updateOrInsert(['product_id' => (string) $p['id'], 'denom_index' => $denom], ['id' => $old?->id ?? (string) Str::uuid(), 'connection' => $provider, 'provider_id' => $offer['providerId'], 'snapshot' => Store::json($offer), 'retail' => $retail, 'active' => $b['active'], 'updated' => time()]);
            app(Store::class)->event($s, 'حفظ ربط فئة رقمية: '.$p['name']);
            app(Store::class)->save($s);

            return ['offers' => $this->offers(), 'revision' => $s['revision']];
        });
    }
}
