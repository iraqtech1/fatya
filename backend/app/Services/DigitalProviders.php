<?php

namespace App\Services;

use App\Exceptions\ApiError;
use App\Exceptions\ProviderTransportError;
use App\Services\Captain\DigitalMoney;
use App\Services\Captain\MasalCatalog;
use App\Services\Captain\Money;
use App\Services\Captain\ProvinceName;
use App\Services\Captain\TopupResult;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Server-only provider transport. Financial calls are made only by the paid-order worker. */
class DigitalProviders
{
    public function catalogRecord(string $provider, ?string $province = null): ?object
    {
        $key = $provider === 'rabiaa' && $province !== null ? $provider.':'.$province : $provider;
        $row = DB::table('digital_catalog')->where('connection', $key)->first();
        if (! $row && $provider === 'rabiaa') {
            $old = DB::table('digital_catalog')->where('connection', $provider)->first();
            $s = $old ? json_decode($old->snapshot, true) : [];
            if (($s['provinceId'] ?? ($s['offers'][0]['provinceId'] ?? null)) === $province) {
                $row = $old;
            }
        }

        return $row;
    }

    private function selectOffer(array $offers, array $input): ?array
    {
        $matches = array_values(array_filter($offers, fn ($r) => $r['providerId'] === (string) ($input['providerId'] ?? '') && (! isset($input['type']) || $r['type'] === $input['type'])));
        if (count($matches) > 1) {
            throw new ApiError('معرّف المزوّد مشترك بين خدمات؛ حدد نوع الخدمة', 422);
        }

        return $matches[0] ?? null;
    }

    public function settings(string $provider): array
    {
        if (! in_array($provider, ['topup', 'rabiaa'], true)) {
            throw new ApiError('المزود غير موجود', 404);
        }
        $c = config('digital.'.$provider);
        $connection = DB::table('digital_connections')->where('provider', $provider)->first();
        if ($connection) {
            $c['enabled'] = (bool) $connection->active;
            if ($connection->credential) {
                $c['key'] = Crypt::decryptString($connection->credential);
            }
        }
        $c['version'] = (int) ($connection?->version ?? 0);
        $c['owner_city'] = $connection?->owner_city ?? ($c['owner_city'] ?? '');
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

    public function request(string $provider, string $path, array $query = [], bool $post = false, ?string $manualOrderId = null): mixed
    {
        $c = $this->settings($provider);
        $allowed = ['topup' => ['/api/v1/inventory', '/api/v1/products', '/api/v1/checkEligibility', '/api/v1/transactions'], 'rabiaa' => ['/vendor/provinces', '/vendor/bein/provinces', '/vendor/catalog', '/vendor/hold', '/vendor/purchase', '/vendor/transactions']];
        $financial = in_array($path, ['/api/v1/transactions', '/vendor/hold', '/vendor/purchase'], true);
        if (! in_array($path, $allowed[$provider], true) || $post !== ($financial || $path === '/api/v1/checkEligibility')) {
            throw new ApiError('مسار الشركة غير معتمد', 422);
        }
        if ($financial && (! $manualOrderId || (! config('digital.purchases_enabled') && ! DigitalManualPayments::approved($manualOrderId, $provider)))) {
            throw new ApiError('تنفيذ المشتريات الخارجية غير مفعّل لهذا الطلب', 409);
        }
        $dispatch = $financial ? DigitalDispatches::begin($manualOrderId, $provider, $c['version'], $path) : null;
        $status = null;
        $responseHash = null;
        $outcome = 'transport_uncertain';
        try {
            $http = Http::acceptJson()->withHeaders([$provider === 'topup' ? 'x-api-key' : 'X-API-Key' => $c['key']])->connectTimeout(8)->timeout(25)->withoutRedirecting();
            $url = rtrim($c['base'], '/').$path;
            // No retries: even an HTTP error can follow an accepted financial request.
            $r = $post ? $http->post($url, $query) : $http->get($url, $query);
            $status = $r->status();
            $responseHash = hash('sha256', $r->body());
            $outcome = 'http_error';
            if (! $r->successful()) {
                $rejection = strlen($r->body()) <= 4 * 1024 * 1024 ? ProviderJson::decode($r->body()) : null;
                if ($provider === 'topup' && $path === '/api/v1/transactions' && in_array($status, [400, 401], true) && is_array($rejection) && (TopupResult::classify($rejection, $query['mobile'] ?? '')['status'] ?? '') === 'failed') {
                    if ($dispatch) {
                        DigitalDispatches::finish($dispatch, 'response_received', $status, $responseHash);
                    }

                    return $rejection;
                }
                throw new \RuntimeException;
            }
            $outcome = 'invalid_response';
            if (strlen($r->body()) > 4 * 1024 * 1024) {
                throw new \RuntimeException;
            }
            $data = ProviderJson::decode($r->body());
            if ($dispatch) {
                DigitalDispatches::finish($dispatch, 'response_received', $status, $responseHash);
            }

            return $data;
        } catch (\Throwable) {
            if ($dispatch) {
                DigitalDispatches::finish($dispatch, $outcome, $status, $responseHash);
            }
            // Provider bodies and transport exception messages may contain PINs or credentials.
            throw new ProviderTransportError('provider_'.$outcome);
        }
    }

    private function read(string $provider, string $path, array $query = [], bool $eligibility = false): mixed
    {
        return $this->request($provider, $path, $query, $eligibility);
    }

    public function status(): array
    {
        return array_map(function ($p) {
            $c = config('digital.'.$p);
            $connection = DB::table('digital_connections')->where('provider', $p)->first();
            $configured = $connection ? (bool) ($connection->active && ($connection->credential || $c['key'])) : (bool) ($c['enabled'] && $c['key']);
            $snapshot = DB::table('digital_catalog')->where(fn ($q) => $q->where('connection', $p)->orWhere('connection', 'like', $p.':%'))->orderByDesc('synced')->first();

            return ['id' => $p, 'name' => $p === 'topup' ? 'التوب أب' : 'الرابعة', 'configured' => $configured, 'active' => $connection ? (bool) $connection->active : (bool) $c['enabled'], 'hasCredential' => (bool) ($connection?->credential || $c['key']), 'version' => (int) ($connection?->version ?? 0), 'ownerCity' => $connection?->owner_city ?? ($c['owner_city'] ?? ''), 'balance' => $connection?->balance, 'balanceUpdatedAt' => $connection?->balance_updated ? date(DATE_ATOM, $connection->balance_updated) : null, 'lastSync' => $snapshot ? date(DATE_ATOM, $snapshot->synced) : null, 'balanceSupported' => $p === 'topup'];
        }, ['topup', 'rabiaa']);
    }

    public function balance(): array
    {
        $version = $this->settings('topup')['version'];
        $raw = $this->read('topup', '/api/v1/inventory');
        $value = is_array($raw) ? ($raw['remaining_balance'] ?? $raw['data']['remaining_balance'] ?? null) : $raw;
        try {
            $amount = StockOrders::money(is_scalar($value) ? (string) $value : null);
        } catch (ApiError) {
            throw new ApiError('رد رصيد الشركة غير مفهوم', 502);
        }

        DB::transaction(function () use ($version, $amount) {
            app(Store::class)->read(true);
            if ($this->settings('topup')['version'] !== $version) {
                throw new ApiError('مفتاح الربط تغير أثناء جلب الرصيد', 409);
            }DB::table('digital_connections')->updateOrInsert(['provider' => 'topup'], ['version' => $version, 'active' => true, 'balance' => $amount, 'balance_updated' => time()]);
        });

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
        $version = $this->settings('rabiaa')['version'];
        $raw = $this->read('rabiaa', $premium ? '/vendor/bein/provinces' : '/vendor/provinces');
        $rows = $raw['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 1000) {
            throw new ApiError('رد المحافظات غير مفهوم', 502);
        }

        $result = array_values(array_map(fn ($r) => ['id' => (string) ($r['id'] ?? ''), 'title' => CatalogEditor::text($r['title'] ?? $r['name'] ?? '', 100)], $rows));
        if ($premium) {
            DB::transaction(function () use ($result, $version) {
                app(Store::class)->read(true);
                if ($this->settings('rabiaa')['version'] !== $version) {
                    throw new ApiError('مفتاح الشركة تغير', 409);
                }DB::table('digital_connections')->updateOrInsert(['provider' => 'rabiaa'], ['version' => $version, 'active' => true, 'premium_provinces' => Store::json($result)]);
            });
        }

        return $result;
    }

    public function catalog(string $provider, mixed $province = null, bool $preview = false): array
    {
        $scope = $provider === 'rabiaa' ? $this->settings($provider)['owner_city'] : 'all';
        $lock = Cache::lock('digital-catalog:'.$provider.':'.$scope, 120);
        if (! $lock->get()) {
            throw new ApiError('توجد مزامنة لنفس المزود والمنطقة؛ انتظر اكتمالها', 409);
        }
        try {
            return $this->readCatalog($provider, $province, $preview, $lock);
        } finally {
            $lock->release();
        }
    }

    private function readCatalog(string $provider, mixed $province, bool $preview, Lock $lock): array
    {
        $version = $this->settings($provider)['version'];
        if ($provider === 'rabiaa') {
            $city = app(ProvinceName::class)->name($this->settings('rabiaa')['owner_city']);
            if ($city === '') {
                throw new ApiError('حدد محافظة صاحب توكن الرابعة قبل جلب الفئات', 422);
            }
            $matched = array_values(array_filter($this->provinces(), fn ($row) => app(ProvinceName::class)->name($row['title']) === $city));
            if (count($matched) !== 1 || ($province !== null && (string) $province !== $matched[0]['id'])) {
                throw new ApiError('تعذرت مطابقة محافظة صاحب التوكن؛ لا يمكن عرض محافظات أخرى', 422);
            }
            $province = $matched[0]['id'];
        }
        $raw = $this->read($provider, $provider === 'topup' ? '/api/v1/products' : '/vendor/catalog', $provider === 'topup' ? [] : ['provinceId' => $province]);
        $rows = $provider === 'topup' ? ($raw['products'] ?? null) : ($raw['data'] ?? null);
        if (($provider === 'topup' && ($raw['status'] ?? null) !== 'ok') || ! is_array($rows) || ! array_is_list($rows) || count($rows) > 5000) {
            throw new ApiError('رد كتالوج الشركة غير مفهوم', 502);
        }
        $pagination = $raw['meta']['pagination'] ?? $raw['pagination'] ?? [];
        if (($raw['partial'] ?? false) === true || ($raw['complete'] ?? true) === false || (int) ($pagination['pageCount'] ?? 1) > 1 || ! empty($pagination['nextPage']) || ! empty($raw['links']['next'])) {
            throw new ApiError('رد الكتالوج جزئي؛ حُفظ الكتالوج السابق دون إيقاف فئاته', 502);
        }
        $offers = [];
        $excluded = [];
        if ($provider === 'topup') {
            $normalized = app(MasalCatalog::class)->normalize($rows);
            $excluded = $normalized['excluded_catalog'];
            $source = collect($rows)->keyBy(fn ($row) => ($row['type'] ?? '').':'.(string) ($row['id'] ?? $row['product_id'] ?? ''));
            foreach ($normalized['catalog'] as $row) {
                $original = $source->get($row['type'].':'.$row['remote_id'], []);
                $offers[] = ['providerId' => $row['remote_id'], 'title' => $row['remote_name'], 'type' => $row['type'], 'parentId' => isset($original['parent_id']) ? (string) $original['parent_id'] : null, 'cost' => StockOrders::money($row['cost']), 'costBasis' => 'catalog_snapshot', 'priceBasis' => 'vendor_price', 'available' => true, 'provinceId' => null, 'groupPath' => $row['group_path'], ...ProviderCatalogMetadata::read($original, (string) config('digital.topup.base'))];
                $at = count($offers) - 1;
                if (! $offers[$at]['images']) {
                    foreach (array_reverse($row['group_path']) as $parent) {
                        $metadata = ProviderCatalogMetadata::read($source->get($row['type'].':'.$parent['remote_id'], []), (string) config('digital.topup.base'));
                        if ($metadata['images']) {
                            $offers[$at]['images'] = $metadata['images'];
                            $offers[$at]['imageSource'] = 'category';
                            break;
                        }
                    }
                }
            }
        } else {
            $identities = [];
            foreach ($rows as $row) {
                if (! is_array($row) || ! ctype_digit((string) ($row['catalogId'] ?? '')) || (int) $row['catalogId'] < 1 || ! is_string($row['title'] ?? null) || trim($row['title']) === '' || mb_strlen($row['title']) > 200 || ! in_array($row['packageType'] ?? null, ['standard', 'premium'], true)) {
                    throw new ApiError('فئة الرابعة لا تطابق عقد الشركة', 422);
                }
                if (isset($row['provinceId']) && (string) $row['provinceId'] !== $province || isset($row['province']) && app(ProvinceName::class)->name($row['province']) !== $city) {
                    throw new ApiError('محافظة الفئة لا تطابق صاحب التوكن', 422);
                }
                $id = (string) $row['catalogId'];
                if (isset($identities[$id])) {
                    throw new ApiError('فئة الشركة مكررة؛ لم يستبدل الكتالوج السابق', 422);
                }
                $identities[$id] = true;
                $cost = Money::decimal(DigitalMoney::minor($row['publicPrice'] ?? null, 'cost', true));
                $offers[] = ['providerId' => $id, 'title' => trim($row['title']), 'type' => $row['packageType'], 'parentId' => null, 'cost' => StockOrders::money($cost), 'costBasis' => 'catalog_snapshot', 'priceBasis' => 'public_price', 'available' => ($row['inStock'] ?? null) === true, 'provinceId' => $province, 'province' => $matched[0]['title'], 'groupPath' => [], ...ProviderCatalogMetadata::read($row, (string) config('digital.rabiaa.base'))];
            }
        }
        $byId = collect($offers)->keyBy(fn ($o) => $o['type'].':'.$o['providerId']);
        foreach ($offers as &$o) {
            if (! $o['images'] && $o['parentId']) {
                $parent = $byId->get($o['type'].':'.$o['parentId']);
                if ($parent && $parent['images']) {
                    $o['images'] = $parent['images'];
                    $o['imageSource'] = 'category';
                }
            }
        } unset($o);
        $subscriberProvinces = $provider === 'rabiaa' && collect($offers)->contains('type', 'premium') ? $this->provinces(true) : [];
        $snapshot = ['provider' => $provider, 'offers' => $offers, 'subscriberProvinces' => $subscriberProvinces, 'excluded' => $excluded, 'provinceId' => $province, 'syncedAt' => now()->toIso8601String()];
        $snapshot['hash'] = hash('sha256', Store::json($snapshot));
        if ($preview && $provider === 'topup') {
            return DB::transaction(function () use ($provider, $snapshot, $version, $raw, $lock) {
                app(Store::class)->read(true);
                if (! $lock->isOwnedByCurrentProcess()) {
                    throw new ApiError('انتهت مهلة المزامنة؛ لم تُحفظ اللقطة', 409);
                }
                if ($this->settings($provider)['version'] !== $version) {
                    throw new ApiError('تغير مفتاح الربط؛ أعد المعاينة', 409);
                }
                $id = DB::table('digital_catalog_previews')->insertGetId(['provider' => $provider, 'connection_version' => $version, 'catalog_hash' => $snapshot['hash'], 'encrypted_snapshot' => Crypt::encryptString(Store::json(['snapshot' => $snapshot, 'raw' => $raw])), 'created' => time(), 'accepted' => false]);
                $prior = $this->catalogRecord($provider);
                $before = collect($prior ? json_decode($prior->snapshot, true)['offers'] : [])->keyBy('providerId');
                $changes = [];
                foreach ($snapshot['offers'] as $offer) {
                    $old = $before->get($offer['providerId']);
                    $changes[] = ['providerId' => $offer['providerId'], 'title' => $offer['title'], 'change' => ! $old ? 'new' : ($old['cost'] !== $offer['cost'] || $old['title'] !== $offer['title'] ? 'changed' : 'unchanged')];
                }
                foreach ($before as $offer) {
                    if (! collect($snapshot['offers'])->contains('providerId', $offer['providerId'])) {
                        $changes[] = ['providerId' => $offer['providerId'], 'title' => $offer['title'], 'change' => 'retired'];
                    }
                }

                return $snapshot + ['preview' => true, 'previewId' => $id, 'connectionVersion' => $version, 'changes' => $changes];
            });
        }

        return $this->persistCatalog($provider, $snapshot, $version, $province, $raw, $lock);
    }

    private function persistCatalog(string $provider, array $snapshot, int $version, ?string $province, array $raw, Lock $lock): array
    {
        DB::transaction(function () use ($provider, $snapshot, $version, $province, $raw, $lock) {
            app(Store::class)->read(true);
            if (! $lock->isOwnedByCurrentProcess()) {
                throw new ApiError('انتهت مهلة المزامنة؛ لم يُستبدل الكتالوج', 409);
            }
            if ($this->settings($provider)['version'] !== $version) {
                throw new ApiError('مفتاح الربط تغير أثناء جلب الكتالوج', 409);
            }
            $key = $provider === 'rabiaa' ? $provider.':'.$province : $provider;
            DB::table('digital_catalog')->updateOrInsert(['connection' => $key], ['snapshot' => Store::json($snapshot + ['encryptedSource' => Crypt::encryptString(Store::json($raw))]), 'synced' => time()]);
            // Successful empty lists retire their previous mappings; failures never replace a snapshot.
            foreach (DB::table('digital_offers')->where('connection', $provider)->get() as $row) {
                $old = json_decode($row->snapshot, true);
                if ($provider === 'rabiaa' && ($old['provinceId'] ?? null) !== $province) {
                    continue;
                }
                $fresh = collect($snapshot['offers'])->first(fn ($o) => $o['providerId'] === $row->provider_id && $o['type'] === ($old['type'] ?? ''));
                if (! $fresh || ! $fresh['available']) {
                    DB::table('digital_offers')->where('id', $row->id)->update(['active' => false, 'updated' => time()]);
                } else {
                    // Matching an old wholesale price does not authorize an automatic retail change.
                    DB::table('digital_offers')->where('id', $row->id)->update(['snapshot' => Store::json($fresh), 'updated' => time()]);
                }
            }
        });

        return $snapshot;
    }

    public function acceptCatalog(array $input): array
    {
        $lock = Cache::lock('digital-catalog:topup:all', 120);
        if (! $lock->get()) {
            throw new ApiError('توجد مزامنة أو عملية اعتماد لقطة جارية', 409);
        }
        try {
            return $this->acceptLockedCatalog($input, $lock);
        } finally {
            $lock->release();
        }
    }

    private function acceptLockedCatalog(array $input, Lock $lock): array
    {
        return DB::transaction(function () use ($input, $lock) {
            app(Store::class)->read(true);
            $id = $input['previewId'] ?? null;
            if (! is_int($id)) {
                throw new ApiError('اختر لقطة معاينة صحيحة', 422);
            }
            $row = DB::table('digital_catalog_previews')->where('id', $id)->where('provider', 'topup')->lockForUpdate()->first();
            if (! $row || $row->catalog_hash !== ($input['catalogHash'] ?? null) || (int) $row->connection_version !== ($input['connectionVersion'] ?? null) || $this->settings('topup')['version'] !== (int) $row->connection_version) {
                throw new ApiError('اللقطة أو مفتاح الربط تغير؛ أعد المعاينة', 409);
            }
            $payload = json_decode(Crypt::decryptString($row->encrypted_snapshot), true);
            if ($row->accepted) {
                return $payload['snapshot'];
            }
            if ($row->created < time() - 1800 || DB::table('digital_catalog_previews')->where('provider', 'topup')->where('id', '>', $id)->exists()) {
                throw new ApiError('توجد لقطة أحدث أو انتهت المعاينة؛ أعد الجلب', 409);
            }
            $snapshot = $this->persistCatalog('topup', $payload['snapshot'], (int) $row->connection_version, null, $payload['raw'], $lock);
            DB::table('digital_catalog_previews')->where('id', $id)->update(['accepted' => true]);

            return $snapshot;
        });
    }

    public function eligibility(array $b): array
    {
        $mobile = self::mobile($b['mobile'] ?? null);
        if ($mobile !== self::mobile($b['confirmMobile'] ?? null)) {
            throw new ApiError('رقما الهاتف غير متطابقين', 422);
        }
        // Re-read current product price and availability instead of trusting fields from the browser.
        $catalog = $this->catalog('topup');
        $offer = $this->selectOffer($catalog['offers'], $b);
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
            $catalog = $this->catalogRecord($provider, isset($b['provinceId']) ? (string) $b['provinceId'] : null);
            if (! $catalog && $provider === 'rabiaa' && ! isset($b['provinceId'])) {
                $catalog = DB::table('digital_catalog')->where('connection', 'like', 'rabiaa:%')->get()->first(fn ($r) => (json_decode($r->snapshot, true)['hash'] ?? null) === ($b['catalogHash'] ?? null));
            }
            $snapshot = $catalog ? json_decode($catalog->snapshot, true) : null;
            if (! $snapshot || ($b['catalogHash'] ?? null) !== $snapshot['hash']) {
                throw new ApiError('كتالوج الشركة تغير؛ اجلب الفئات من جديد', 409);
            }
            $offer = $this->selectOffer($snapshot['offers'], $b);
            if (! $offer || ($b['active'] && (! $offer['available'] || $catalog->synced < time() - 900))) {
                throw new ApiError('حدّث كتالوج الشركة واختر فئة متوفرة', 409);
            }
            if ($b['active'] && $offer['cost'] !== null && BigDecimal::of($retail)->isLessThan($offer['cost'])) {
                throw new ApiError('سعر البيع أقل من سعر الشركة؛ حدّث التسعير', 422);
            }
            $old = DB::table('digital_offers')->where('product_id', (string) $p['id'])->where('denom_index', $denom)->first();
            if (request()->attributes->has('adminAccess')) {
                app(AdminPolicy::class)->digitalOffer($old, ['product_id' => (string) $p['id'], 'provider_id' => $offer['providerId'], 'connection' => $provider, 'retail' => $retail, 'active' => $b['active']], false);
            }
            DB::table('digital_offers')->updateOrInsert(['product_id' => (string) $p['id'], 'denom_index' => $denom], ['id' => $old?->id ?? (string) Str::uuid(), 'connection' => $provider, 'provider_id' => $offer['providerId'], 'snapshot' => Store::json($offer), 'retail' => $retail, 'active' => $b['active'], 'updated' => time()]);
            foreach ($s['products'] as &$product) {
                if ((string) $product['id'] === (string) $p['id']) {
                    $product['prices'][$denom] = $retail;
                    break;
                }
            }unset($product);
            if (! $old || StockOrders::money((string) $old->retail) !== $retail) {
                ProcurementAudit::record('prices.updated', (string) $p['id'], ['reason' => CatalogEditor::text($b['reason'] ?? '', 500) ?: 'تعديل من محرر ربط API', 'revision' => $s['revision'] + 1, 'source' => 'digital_offer', 'changes' => [['key' => 'product:'.$p['id'].':'.$denom, 'name' => $offer['title'], 'old' => $old ? StockOrders::money((string) $old->retail) : null, 'new' => $retail]]]);
            }
            app(Store::class)->event($s, 'حفظ ربط فئة رقمية: '.$p['name']);
            app(Store::class)->save($s);

            return ['offers' => $this->offers(), 'revision' => $s['revision']];
        });
    }
}
