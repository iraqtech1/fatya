<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Publication is independent of enabling external financial transactions. */
class DigitalPublications
{
    public function listing(): array
    {
        return DB::table('digital_publications')->orderBy('updated')->get()->map(fn ($r) => ['id' => $r->id, 'productId' => $r->product_id, 'provider' => $r->connection, 'providerId' => $r->provider_id, 'snapshot' => json_decode($r->snapshot, true), 'retail' => (string) $r->retail, 'published' => (bool) $r->active, 'version' => (int) $r->version])->all();
    }

    public function catalogs(): array
    {
        return DB::table('digital_catalog')->get()->map(function ($r) {
            $s = json_decode($r->snapshot, true);
            unset($s['encryptedSource']);

            return $s;
        })->all();
    }

    public function save(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $store = app(Store::class);
            $s = $store->read(true);
            if (($b['revision'] ?? null) !== $s['revision']) {
                throw new ApiError('البيانات تغيرت؛ حدّث الصفحة', 409);
            }
            if (($b['provider'] ?? null) !== 'topup' || ! is_bool($b['published'] ?? null)) {
                throw new ApiError('بيانات النشر غير صحيحة', 422);
            }
            if (($b['published'] ?? null) === false && isset($b['id'])) {
                if (! is_string($b['id']) || ! Str::isUuid($b['id'])) {
                    throw new ApiError('معرّف النشر غير صحيح', 422);
                }
                $old = DB::table('digital_publications')->where('id', $b['id'])->first();
                if (! $old || (int) $old->version !== ($b['version'] ?? null)) {
                    throw new ApiError('حالة النشر تغيرت؛ حدّث الصفحة', 409);
                }
                DB::table('digital_publications')->where('id', $old->id)->update(['active' => false, 'version' => (int) $old->version + 1, 'updated' => time()]);
                ProcurementAudit::record('digital.unpublish', $old->id, []);
                $store->event($s, 'إيقاف نشر خدمة TOPUP');
                $store->save($s);

                return ['publications' => $this->listing(), 'revision' => $s['revision']];
            }
            $record = app(DigitalProviders::class)->catalogRecord('topup');
            $catalog = $record ? json_decode($record->snapshot, true) : [];
            if (! $catalog || ($catalog['hash'] ?? '') !== ($b['catalogHash'] ?? null)) {
                throw new ApiError('الكتالوج تغير؛ راجع بيانات الفئة', 409);
            }
            $offer = collect($catalog['offers'])->first(fn ($o) => $o['providerId'] === ($b['providerId'] ?? null) && $o['type'] === ($b['type'] ?? null));
            $product = collect($s['products'])->first(fn ($p) => (string) $p['id'] === ($b['productId'] ?? null) && $p['active']);
            if (! $offer || ! $product || ($b['published'] && ! $offer['available'])) {
                throw new ApiError('اختر فئة قابلة للبيع وبطاقة منشورة لعرضها تحتها', 422);
            }
            $price = StockOrders::money($b['retail'] ?? null);
            if (! BigDecimal::of($price)->isGreaterThan(0)) {
                throw new ApiError('حدد سعر بيع أكبر من صفر', 422);
            }
            $identity = hash('sha256', Store::json(['topup', $offer['type'], $offer['providerId']]));
            $old = DB::table('digital_publications')->where('identity', $identity)->first();
            if (($b['version'] ?? 0) !== (int) ($old?->version ?? 0)) {
                throw new ApiError('حالة النشر تغيرت؛ حدّث الصفحة', 409);
            }
            $id = $old?->id ?? (string) Str::uuid();
            DB::table('digital_publications')->updateOrInsert(['identity' => $identity], ['id' => $id, 'product_id' => (string) $product['id'], 'connection' => 'topup', 'provider_id' => $offer['providerId'], 'snapshot' => Store::json($offer), 'retail' => $price, 'active' => $b['published'], 'version' => (int) ($old?->version ?? 0) + 1, 'updated' => time()]);
            ProcurementAudit::record($b['published'] ? 'digital.publish' : 'digital.unpublish', $id, ['productId' => $product['id'], 'retail' => $price]);
            $store->event($s, ($b['published'] ? 'نشر خدمة TOPUP: ' : 'إيقاف نشر خدمة TOPUP: ').$offer['title']);
            $store->save($s);

            return ['publications' => $this->listing(), 'revision' => $s['revision']];
        });
    }

    public function decorate(array &$s): void
    {
        $catalog = app(DigitalProviders::class)->catalogRecord('topup');
        $fresh = $catalog ? json_decode($catalog->snapshot, true) : [];
        $offers = collect($fresh['offers'] ?? [])->keyBy(fn ($o) => $o['type'].':'.$o['providerId']);
        $configured = collect(app(DigitalProviders::class)->status())->firstWhere('id', 'topup')['configured'] ?? false;
        foreach (DB::table('digital_publications')->where('active', true)->get() as $r) {
            $saved = json_decode($r->snapshot, true);
            $o = $offers->get($saved['type'].':'.$r->provider_id);
            foreach ($s['products'] as &$p) {
                if ((string) $p['id'] !== $r->product_id || ! $p['active']) {
                    continue;
                }
                $ready = $configured && $o && $o['available'] && ($o['cost'] === $saved['cost']) && config('digital.manual_topup_enabled');
                // No wholesale price, raw provider details, connection settings or credentials enter public state.
                $p['providerServices'][] = ['id' => $r->id, 'type' => $saved['type'], 'title' => $o['title'] ?? $saved['title'], 'description' => $o['description'] ?? $saved['description'] ?? '', 'price' => (string) $r->retail, 'purchasable' => (bool) $ready, 'payment' => 'Manual'];
            } unset($p);
        }
    }

    public function reserve(array $s, array $customer, array $b, array $p): array
    {
        if (! is_string($b['publicationId'] ?? null) || ! Str::isUuid($b['publicationId'])) {
            throw new ApiError('معرّف الخدمة غير صحيح', 422);
        }
        $r = DB::table('digital_publications')->where('id', $b['publicationId'])->where('product_id', (string) $p['id'])->where('active', true)->first();
        if (! $r || ! config('digital.manual_topup_enabled') || ($b['payment'] ?? null) !== 'Manual' || ($b['denom'] ?? null) !== 0) {
            throw new ApiError('الخدمة غير منشورة أو الدفع اليدوي غير متاح', 409);
        }
        if ((! is_string($b['quotedAmount'] ?? null) && ! is_int($b['quotedAmount'] ?? null) && ! is_float($b['quotedAmount'] ?? null)) || StockOrders::money((string) $b['quotedAmount']) !== StockOrders::money((string) $r->retail)) {
            throw new ApiError('سعر البيع تغير؛ حدّث التطبيق وراجع السعر الجديد', 409);
        }
        $offer = json_decode($r->snapshot, true);
        $p['name'] .= ' · '.$offer['title'];
        $p['unit'] = 'خدمة';
        $p['values'] = [null];

        return app(DigitalSales::class)->reserve($s, $customer, $b, $p, 0, $r);
    }
}
