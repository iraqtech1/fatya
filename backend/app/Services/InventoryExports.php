<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryExports
{
    public function authorize(array $b): void
    {
        $expected = config('fatya.admin_password');
        if (! config('fatya.code_export_enabled') || ! is_string($expected) || strlen($expected) < 12 || ! is_string($b['password'] ?? null) || ! hash_equals($expected, $b['password'])) {
            throw new ApiError('تصدير الأسرار يحتاج صلاحية الإدارة وتأكيد كلمة المرور', 403);
        }
    }

    private function present(object $r): array
    {
        return ['id' => $r->id, 'kind' => $r->kind, 'status' => $r->status, 'version' => (int) $r->version, 'actor' => $r->actor, 'reason' => $r->reason, 'quantity' => (int) $r->quantity, 'createdAt' => date(DATE_ATOM, $r->created), 'expiresAt' => $r->expires ? date(DATE_ATOM, $r->expires) : null, 'filters' => json_decode($r->filters, true)];
    }

    public function listing(): array
    {
        return ['exports' => DB::table('inventory_exports')->orderByDesc('created')->limit(100)->get()->map(fn ($r) => $this->present($r))->all(), 'canExportCodes' => (bool) config('fatya.code_export_enabled')];
    }

    public function create(array $b): array
    {
        $this->authorize($b);
        $key = $b['requestId'] ?? null;
        $kind = $b['kind'] ?? null;
        $reason = CatalogEditor::text($b['reason'] ?? '', 1000);
        if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key) || ! in_array($kind, ['copy', 'withdrawal'], true) || ! $reason) {
            throw new ApiError('حدد نوع التصدير وسببه ومفتاح الطلب', 422);
        }
        $f = app(InventoryReports::class)->filters($b['filters'] ?? []);
        $limit = $b['quantity'] ?? null;
        if (! is_int($limit) || $limit < 1 || $limit > 50000) {
            throw new ApiError('الكمية من 1 إلى 50000', 422);
        }
        $hash = hash('sha256', Store::json([$kind, $reason, $f, $limit]));

        return DB::transaction(function () use ($key, $kind, $reason, $f, $limit, $hash) {
            app(Store::class)->read(true);
            $old = DB::table('inventory_exports')->where('request_id', $key)->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $hash)) {
                    throw new ApiError('مفتاح التصدير مستخدم ببيانات أخرى', 409);
                }

return $this->present($old);
            }
            $q = app(InventoryReports::class)->inventoryQuery($f);
            if ($kind === 'withdrawal') {
                $q->whereNull('withdrawal_id')->whereNull('reserved_order_id')->whereNull('sold_order_id')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now('Asia/Baghdad')->toDateString()));
            }
            $ids = $q->orderBy('id')->limit($limit)->pluck('id')->all();
            if (count($ids) !== $limit) {
                throw new ApiError('الكمية المطلوبة أكبر من البطاقات المطابقة والمتاحة', 422);
            }
            $id = (string) Str::uuid();
            DB::table('inventory_exports')->insert(['id' => $id, 'request_id' => $key, 'request_hash' => $hash, 'kind' => $kind, 'status' => $kind === 'copy' ? 'approved' : 'pending', 'actor' => ProcurementAudit::actor(), 'reason' => $reason, 'filters' => Store::json($f), 'quantity' => count($ids), 'encrypted_ids' => Crypt::encryptString(Store::json($ids)), 'created' => time(), 'expires' => $kind === 'copy' ? time() + 900 : null]);
            ProcurementAudit::record('export_requested', $id, ['kind' => $kind, 'quantity' => count($ids), 'reason' => $reason, 'filters' => $f]);

            return $this->present(DB::table('inventory_exports')->where('id', $id)->first());
        });
    }

    public function review(array $b): array
    {
        $this->authorize($b);

        return DB::transaction(function () use ($b) {
            $store = app(Store::class);
            $s = $store->read(true);
            $r = DB::table('inventory_exports')->where('id', $b['id'] ?? '')->lockForUpdate()->first();
            if (! $r || $r->kind !== 'withdrawal' || ($b['version'] ?? null) !== (int) $r->version || $r->status !== 'pending' || ! in_array($b['decision'] ?? null, ['approve', 'reject'], true)) {
                throw new ApiError('طلب السحب تغير؛ أعد القراءة', 409);
            }
            $approve = $b['decision'] === 'approve';
            if ($approve) {
                $ids = json_decode(Crypt::decryptString($r->encrypted_ids), true);
                foreach (array_chunk($ids, 500) as $chunk) {
                    $q = DB::table('inventory_cards')->whereIn('id', $chunk)->whereNull('withdrawal_id')->whereNull('reserved_order_id')->whereNull('sold_order_id')->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', now('Asia/Baghdad')->toDateString()));
                    if ($q->update(['withdrawal_id' => $r->id]) !== count($chunk)) {
                        throw new ApiError('بعض البطاقات لم تعد متاحة؛ أنشئ طلب سحب جديد', 409);
                    }
                }
            }
            DB::table('inventory_exports')->where('id', $r->id)->update(['status' => $approve ? 'approved' : 'rejected', 'version' => $r->version + 1, 'expires' => $approve ? time() + 900 : null, 'approved_at' => $approve ? time() : null]);
            ProcurementAudit::record('withdrawal_review', $r->id, ['decision' => $b['decision'], 'quantity' => $r->quantity]);
            app(Inventory::class)->refreshStock($s);
            $store->event($s, $approve ? 'اعتماد سحب مخزون' : 'رفض سحب مخزون');
            $store->save($s);

            return $this->present(DB::table('inventory_exports')->where('id', $r->id)->first());
        });
    }

    public function download(array $b): array
    {
        $this->authorize($b);

        return DB::transaction(function () use ($b) {
            $r = DB::table('inventory_exports')->where('id', $b['id'] ?? '')->lockForUpdate()->first();
            if (! $r || $r->status !== 'approved' || ! $r->expires || $r->expires <= time()) {
                throw new ApiError('صلاحية التنزيل انتهت أو لم يعتمد الطلب', 410);
            }
            $rows = [];
            foreach (array_chunk(json_decode(Crypt::decryptString($r->encrypted_ids), true), 500) as $ids) {
                foreach (DB::table('inventory_cards')->whereIn('id', $ids)->orderBy('id')->get() as $c) {
                    if ($r->kind === 'withdrawal' && $c->withdrawal_id !== $r->id) {
                        throw new ApiError('تغيرت بيانات السحب', 409);
                    }
                    $rows[] = ['id' => $c->id, 'productId' => $c->product_id, 'value' => (string) $c->denomination, 'pin' => Crypt::decryptString($c->encrypted_code), 'serial' => $c->serial ?? '', 'expiry' => $c->expires_on ?? '', 'batchId' => $c->batch_id ?? '', ...CardFields::details($c)];
                }
            }
            ProcurementAudit::record('export_download', $r->id, ['quantity' => count($rows), 'kind' => $r->kind]);

            return ['id' => $r->id, 'kind' => $r->kind, 'rows' => $rows];
        });
    }

    public function renew(array $b): array
    {
        $this->authorize($b);

        return DB::transaction(function () use ($b) {
            $r = DB::table('inventory_exports')->where('id', $b['id'] ?? '')->lockForUpdate()->first();
            if (! $r || $r->status !== 'approved' || (int) $r->version !== ($b['version'] ?? null)) {
                throw new ApiError('الطلب تغير أو لم يعتمد', 409);
            }
            DB::table('inventory_exports')->where('id', $r->id)->update(['expires' => time() + 900, 'version' => $r->version + 1]);
            ProcurementAudit::record('export_renewed', $r->id, ['kind' => $r->kind, 'quantity' => $r->quantity]);

            return $this->present(DB::table('inventory_exports')->where('id',$r->id)->first());
        });
    }
}
