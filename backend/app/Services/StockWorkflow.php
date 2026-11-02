<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockWorkflow
{
    public function policy(): array
    {
        $p = DB::table('procurement_settings')->where('id', 1)->first();

        return ['required' => (bool) $p->approval_required, 'version' => (int) $p->version];
    }

    public function savePolicy(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $store = app(Store::class);
            $s = $store->read(true);
            $p = $this->policy();
            if (($b['version'] ?? null) !== $p['version']) {
                throw new ApiError('سياسة الموافقة تغيرت؛ حدّث الصفحة', 409);
            }
            if (! is_bool($b['required'] ?? null)) {
                throw new ApiError('سياسة الموافقة غير صحيحة', 422);
            }
            DB::table('procurement_settings')->where('id', 1)->update(['approval_required' => $b['required'], 'version' => $p['version'] + 1]);
            $store->event($s, 'تحديث سياسة اعتماد المخزون');
            $store->save($s);

            return $this->policy();
        });
    }

    private function present(object $r): array
    {
        return [...json_decode($r->summary, true), 'id' => $r->id, 'status' => $r->status, 'version' => (int) $r->version, 'reason' => $r->reason, 'createdAt' => date(DATE_ATOM, $r->created)];
    }

    public function submissions(): array
    {
        return DB::table('stock_submissions')->orderByDesc('updated')->limit(100)->get()->map(fn ($r) => $this->present($r))->all();
    }

    private function mutation(array $b, callable $action): array
    {
        $key = $b['requestId'] ?? null;
        if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key)) {
            throw new ApiError('مفتاح منع التكرار غير صحيح', 422);
        }

        return DB::transaction(function () use ($b, $key, $action) {
            app(Store::class)->read(true);
            $hash = hash('sha256', Store::json($b));
            $old = DB::table('procurement_mutations')->where('request_id', $key)->first();
            if ($old) {
                if (! hash_equals($old->hash, $hash)) {
                    throw new ApiError('مفتاح الإرسال مستخدم ببيانات مختلفة', 409);
                }

                return json_decode($old->result, true);
            }
            $result = $action();
            DB::table('procurement_mutations')->insert(['request_id' => $key, 'hash' => $hash, 'result' => Store::json($result)]);

            return $result;
        });
    }

    public function submit(array $b): array
    {
        return $this->mutation($b, function () use ($b) {
            $s = app(Store::class)->read(true);
            $p = DB::table('stock_order_previews')->where('id', $b['previewId'] ?? '')->first();
            if (! $p || $p->expires < time() || ($b['previewHash'] ?? null) !== $p->hash || (int) $p->revision !== $s['revision']) {
                throw new ApiError('أعد معاينة الطلبية قبل الإرسال', 409);
            }
            $summary = json_decode(Crypt::decryptString($p->encrypted_draft), true)['summary'];
            if (! $summary['quantity'] || ($summary['rejected'] && ($b['excludeRejected'] ?? null) !== true) || collect($summary['lines'])->contains(fn ($l) => ! $l['accepted'])) {
                throw new ApiError('صحح الملفات الخالية وأكد استبعاد المرفوض', 422);
            }
            $id = $b['resubmitId'] ?? (string) Str::uuid();
            $old = DB::table('stock_submissions')->where('id', $id)->first();
            if (isset($b['resubmitId']) && (! $old || $old->status !== 'returned' || ($b['version'] ?? null) !== (int) $old->version)) {
                throw new ApiError('الطلبية ليست معادة للتصحيح أو تغيرت', 409);
            }
            $values = ['status' => 'pending', 'version' => $old ? (int) $old->version + 1 : 0, 'summary' => Store::json($summary), 'catalog_evidence' => Store::json($summary['lines']), 'reason' => null, 'updated' => time()];
            if ($old) {
                DB::table('stock_submissions')->where('id', $id)->update($values);
            } else {
                DB::table('stock_submissions')->insert($values + ['id' => $id, 'request_id' => $b['requestId'], 'request_hash' => hash('sha256', Store::json($b)), 'created' => time()]);
            }
            DB::table('stock_submission_chunks')->where('submission_id', $id)->delete();
            foreach (DB::table('stock_order_preview_chunks')->where('preview_id', $p->id)->orderBy('part')->get() as $part) {
                DB::table('stock_submission_chunks')->insert(['submission_id' => $id, 'part' => $part->part, 'encrypted_cards' => $part->encrypted_cards]);
            }
            DB::table('stock_order_previews')->where('id', $p->id)->delete();
            $store = app(Store::class);
            $store->event($s, 'إرسال طلبية مخزون للموافقة');
            $store->save($s);

            return $this->present(DB::table('stock_submissions')->where('id', $id)->first());
        });
    }

    public function review(array $b): array
    {
        return $this->mutation($b, function () use ($b) {
            $r = DB::table('stock_submissions')->where('id', $b['id'] ?? '')->lockForUpdate()->first();
            if (! $r || $r->status !== 'pending' || ($b['version'] ?? null) !== (int) $r->version || ! in_array($b['decision'] ?? '', ['approve', 'return', 'reject'], true)) {
                throw new ApiError('الطلبية تغيرت أو القرار غير متاح', 409);
            }
            $store = app(Store::class);
            $s = $store->read(true);
            $reason = CatalogEditor::text($b['reason'] ?? '', 1000);
            if ($b['decision'] !== 'approve' && ! $reason) {
                throw new ApiError('اكتب سبب الإعادة أو الرفض', 422);
            }
            $summary = json_decode($r->summary, true);
            if ($b['decision'] === 'approve') {
                $lines = [];
                foreach (json_decode($r->catalog_evidence, true) as $l) {
                    $p = collect($s['products'])->first(fn ($p) => (string) $p['id'] === $l['productId']);
                    if (! $p || ! $p['active'] || (isset($l['importFields']) && ProductFields::policy($p, $l['denom']) !== $l['importFields']) || ($p['values'][$l['denom']] ?? null) != $l['value'] || StockOrders::money((string) ($p['prices'][$l['denom']] ?? '')) !== $l['retail']) {
                        throw new ApiError('الفئات أو الأسعار تغيرت؛ أعد الطلبية للتصحيح', 409);
                    }
                    $lines[] = ['productId' => $l['productId'], 'denom' => $l['denom'], 'cost' => $l['cost'], 'fileName' => $l['fileName'] ?? '', 'rows' => []];
                }
                foreach (DB::table('stock_submission_chunks')->where('submission_id', $r->id)->orderBy('part')->get() as $part) {
                    foreach (json_decode(Crypt::decryptString($part->encrypted_cards), true) as $card) {
                        $lines[$card['line'] - 1]['rows'][] = [...CardFields::details($card), 'sourceRow' => $card['row'], 'pin' => Crypt::decryptString($card['encrypted_code']), 'serial' => $card['serial'] ?? '', 'expiry' => $card['expires_on'] ?? ''];
                    }
                }
                $service = app(StockOrders::class);
                $preview = $service->preview(['revision' => $s['revision'], 'supplier' => $summary['supplier'], 'source' => $summary['source'], 'city' => $summary['city'], 'lines' => $lines]);
                if ($preview['rejected'] || $preview['quantity'] !== $summary['quantity']) {
                    throw new ApiError('المخزون أو الصلاحية تغيرت؛ أعد الطلبية للتصحيح', 409);
                }
                $approved = $service->approve(['previewId' => $preview['previewId'], 'previewHash' => $preview['previewHash'], 'excludeRejected' => false, 'requestId' => 'review-'.$r->id], true);
            }
            if (isset($approved)) {
                $summary = [...$summary, 'batchId' => $approved['id'], 'added' => $approved['quantity']];
            }
            ProcurementAudit::record('stock_review', $r->id, ['decision' => $b['decision'], 'reason' => $reason]);
            DB::table('stock_submissions')->where('id', $r->id)->update(['status' => ['approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'][$b['decision']], 'version' => $r->version + 1, 'reason' => $reason, 'summary' => Store::json($summary), 'updated' => time()]);
            $s = $store->read(true);
            $store->event($s, 'مراجعة طلبية مخزون: '.$b['decision']);
            $store->save($s);

            return $this->present(DB::table('stock_submissions')->where('id', $r->id)->first());
        });
    }
}
