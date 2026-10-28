<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockOrders
{
    public function __construct(private Store $store, private Inventory $inventory) {}

    public static function money(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,16}(\.\d{1,3})?$/D', $value)) {
            throw new ApiError('التكلفة والسعر يجب أن يكونا نصاً رقمياً موجباً وبثلاث منازل كحد أقصى', 422);
        }

        return (string) BigDecimal::of($value)->toScale(3);
    }

    public function batches(): array
    {
        return DB::table('stock_batches')->orderByDesc('created')->limit(100)->get()->map(fn ($r) => json_decode($r->summary, true))->all();
    }

    public function preview(array $b): array
    {
        return DB::transaction(function () use ($b) {
            $s = $this->store->read(true);
            if (($b['revision'] ?? null) !== $s['revision']) {
                throw new ApiError('البيانات تغيرت حدّث الصفحة', 409);
            }
            $lines = $b['lines'] ?? null;
            if (! is_array($lines) || ! array_is_list($lines) || ! count($lines) || count($lines) > 200) {
                throw new ApiError('اختر من فئة واحدة إلى 200 فئة', 422);
            }
            $supplier = CatalogEditor::text($b['supplier'] ?? '', 100);
            $source = CatalogEditor::text($b['source'] ?? '', 100);
            $city = CatalogEditor::text($b['city'] ?? '', 100);
            if (! $supplier || ! $source) {
                throw new ApiError('اسم المجهز ومصدر الطلبية مطلوبان', 422);
            }
            $seen = $serials = $accepted = $errors = $warnings = $summaries = [];
            $total = BigDecimal::zero()->toScale(3);
            $nominal = BigDecimal::zero()->toScale(3);
            $count = 0;
            foreach ($lines as $li => $line) {
                if (! is_array($line)) {
                    throw new ApiError('سطر الفئة غير صحيح', 422);
                }
                $p = collect($s['products'])->first(fn ($p) => (string) $p['id'] === (string) ($line['productId'] ?? ''));
                $di = $line['denom'] ?? null;
                $rows = $line['rows'] ?? null;
                if (! $p || ! is_int($di) || ! isset($p['values'][$di]) || ! is_array($rows) || ! array_is_list($rows)) {
                    throw new ApiError('الفئة أو صفوف الملف غير صحيحة', 422);
                }
                $count += count($rows);
                if ($count > 50000) {
                    throw new ApiError('الحد الأقصى 50000 بطاقة للطلبية', 422);
                }
                $cost = self::money($line['cost'] ?? null);
                $price = self::money((string) $p['prices'][$di]);
                $before = count($accepted);
                foreach ($rows as $ri => $row) {
                    $sourceRow = is_array($row) && is_int($row['sourceRow'] ?? null) && $row['sourceRow'] > 0 && $row['sourceRow'] <= 100000 ? $row['sourceRow'] : $ri + 1;
                    $label = ['line' => $li + 1, 'row' => $sourceRow];
                    if (! is_array($row)) {
                        $errors[] = [...$label, 'reason' => 'صف غير صحيح'];

                        continue;
                    }
                    $pin = $row['pin'] ?? '';
                    $serial = $row['serial'] ?? '';
                    $expiry = ($row['expiry'] ?? '') !== '' ? $row['expiry'] : ($line['expiry'] ?? '');
                    $reason = '';
                    if (! is_string($pin) || ! trim($pin) || strlen($pin) > 256) {
                        $reason = 'PIN مطلوب كنص للحفاظ على الأصفار';
                    } elseif (! is_string($serial) || strlen($serial) > 256) {
                        $reason = 'السيريال يجب أن يكون نصاً';
                    }
                    $pin = is_string($pin) ? trim($pin) : '';
                    $serial = is_string($serial) ? trim($serial) : '';
                    $hash = hash('sha256', $pin);
                    if (! $reason && (isset($seen[$hash]) || ($serial !== '' && isset($serials[$serial])))) {
                        $reason = 'PIN أو سيريال مكرر داخل الطلبية';
                    }
                    $date = null;
                    if ($expiry !== '' && $expiry !== null) {
                        $parsed = is_string($expiry) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiry) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $expiry) : false;
                        if (is_string($expiry) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expiry) && $parsed && $parsed->format('Y-m-d') === $expiry) {
                            $date = $expiry;
                            if ($date < now('Asia/Baghdad')->toDateString()) {
                                $reason = 'بطاقة منتهية الصلاحية';
                            }
                        } else {
                            $warnings[] = [...$label, 'reason' => 'تاريخ غير مفهوم؛ ستقبل البطاقة دون تاريخ'];
                        }
                    }
                    if ($reason) {
                        $errors[] = [...$label, 'reason' => $reason];

                        continue;
                    }
                    $seen[$hash] = true;
                    if ($serial !== '') {
                        $serials[$serial] = true;
                    }
                    $accepted[] = [...$label, 'product_id' => (string) $p['id'], 'denomination' => $p['values'][$di], 'code_hash' => $hash, 'encrypted_code' => Crypt::encryptString($pin), 'serial' => $serial === '' ? null : $serial, 'unit_cost' => $cost, 'expires_on' => $date];
                }
                $summaries[] = ['line' => $li + 1, 'name' => $p['name'], 'value' => $p['values'][$di], 'cost' => $cost, 'retail' => $price, 'candidateCount' => count($accepted) - $before];
            }
            // Bounded lookups avoid loading every encrypted card or exhausting SQL parameter limits.
            $existingHashes = $existingSerials = [];
            foreach (array_chunk(array_keys($seen), 500) as $chunk) {
                $existingHashes += array_fill_keys(DB::table('inventory_cards')->whereIn('code_hash', $chunk)->pluck('code_hash')->all(), true);
            }
            foreach (array_chunk(array_keys($serials), 500) as $chunk) {
                $existingSerials += array_fill_keys(DB::table('inventory_cards')->whereIn('serial', $chunk)->pluck('serial')->all(), true);
            }
            $valid = [];
            foreach ($accepted as $row) {
                if (isset($existingHashes[$row['code_hash']]) || ($row['serial'] !== null && isset($existingSerials[$row['serial']]))) {
                    $errors[] = ['line' => $row['line'], 'row' => $row['row'], 'reason' => 'PIN أو سيريال موجود في المخزون'];
                } else {
                    $valid[] = $row;
                    $total = $total->plus($row['unit_cost']);
                    $nominal = $nominal->plus($summaries[$row['line'] - 1]['retail']);
                }
            }
            foreach ($summaries as &$summary) {
                $n = count(array_filter($valid, fn ($r) => $r['line'] === $summary['line']));
                $summary['accepted'] = $n;
                $summary['costTotal'] = (string) BigDecimal::of($summary['cost'])->multipliedBy($n);
            }
            unset($summary);
            $id = (string) Str::uuid();
            $summary = ['id' => $id, 'supplier' => $supplier, 'source' => $source, 'city' => $city, 'quantity' => count($valid), 'rejected' => count($errors), 'costTotal' => (string) $total, 'nominalTotal' => (string) $nominal, 'currency' => 'IQD', 'lines' => $summaries];
            $draft = Store::json(['cards' => $valid, 'summary' => $summary]);
            $hash = hash_hmac('sha256', $draft, config('app.key'));
            DB::table('stock_order_previews')->where('expires', '<', time())->delete();
            DB::table('stock_order_previews')->insert(['id' => $id, 'hash' => $hash, 'revision' => $s['revision'], 'encrypted_draft' => Crypt::encryptString(Store::json(['summary' => $summary])), 'expires' => time() + 900]);
            // Chunk encrypted evidence to avoid TEXT limits and oversized MariaDB packets.
            foreach (array_chunk($valid, 500) as $part => $cards) {
                DB::table('stock_order_preview_chunks')->insert(['preview_id' => $id, 'part' => $part, 'encrypted_cards' => Crypt::encryptString(Store::json($cards))]);
            }

            return [...$summary, 'previewId' => $id, 'previewHash' => $hash, 'errors' => $errors, 'warnings' => $warnings, 'approvalRequired' => false];
        });
    }

    public function approve(array $b): array
    {
        $key = $b['requestId'] ?? null;
        if (! is_string($key) || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', $key) || ! is_string($b['previewId'] ?? null) || ! is_string($b['previewHash'] ?? null) || ! is_bool($b['excludeRejected'] ?? null)) {
            throw new ApiError('بيانات الاعتماد أو مفتاح منع التكرار غير صحيحة', 422);
        }
        $requestHash = hash('sha256', Store::json([$b['previewId'], $b['previewHash'], $b['excludeRejected']]));

        return DB::transaction(function () use ($b, $key, $requestHash) {
            $s = $this->store->read(true);
            $old = DB::table('stock_batches')->where('request_id', $key)->first();
            if ($old) {
                if (! hash_equals($old->request_hash, $requestHash)) {
                    throw new ApiError('مفتاح الطلب مستخدم ببيانات مختلفة', 409);
                }

                return json_decode($old->summary, true);
            }
            $preview = DB::table('stock_order_previews')->where('id', $b['previewId'])->lockForUpdate()->first();
            if (! $preview || $preview->expires < time() || ! hash_equals($preview->hash, $b['previewHash']) || (int) $preview->revision !== $s['revision']) {
                throw new ApiError('المعاينة تغيرت أو انتهت؛ اطلب معاينة جديدة', 409);
            }
            $draft = json_decode(Crypt::decryptString($preview->encrypted_draft), true, 512, JSON_THROW_ON_ERROR);
            if (! $draft['summary']['quantity'] || ($draft['summary']['rejected'] && ! $b['excludeRejected'])) {
                throw new ApiError('أكد استبعاد المرفوض أو صحح الطلبية', 422);
            }
            $today = now('Asia/Baghdad')->toDateString();
            $id = $draft['summary']['id'];
            // The unique PIN/serial constraints are the final guard against concurrent legacy imports.
            $inserted = 0;
            foreach (DB::table('stock_order_preview_chunks')->where('preview_id', $id)->orderBy('part')->get() as $part) {
                $cards = json_decode(Crypt::decryptString($part->encrypted_cards), true, 512, JSON_THROW_ON_ERROR);
                foreach ($cards as $card) {
                    if ($card['expires_on'] !== null && $card['expires_on'] < $today) {
                        throw new ApiError('تغيرت صلاحية بطاقة؛ اطلب معاينة جديدة', 409);
                    }
                }
                foreach (array_chunk($cards, 100) as $chunk) {
                    DB::table('inventory_cards')->insert(array_map(function ($r) use ($id) {
                        unset($r['line'], $r['row']);

                        return [...$r, 'batch_id' => $id];
                    }, $chunk));
                    $inserted += count($chunk);
                }
            }
            if ($inserted !== $draft['summary']['quantity']) {
                throw new ApiError('بيانات المعاينة غير مكتملة؛ أعد المعاينة', 409);
            }
            $result = [...$draft['summary'], 'status' => 'approved', 'createdAt' => now()->toIso8601String()];
            DB::table('stock_batches')->insert(['id' => $id, 'request_id' => $key, 'request_hash' => $requestHash, 'summary' => Store::json($result), 'created' => time()]);
            DB::table('stock_order_previews')->where('id', $id)->delete();
            array_unshift($s['imports'], ['id' => $id, 'name' => $result['supplier'].' / '.$result['source'], 'count' => $result['quantity'], 'rejected' => $result['rejected'], 'costTotal' => $result['costTotal'], 'date' => $today]);
            $this->inventory->refreshStock($s);
            $this->store->event($s, 'اعتماد طلبية مخزون: '.$result['quantity'].' بطاقة');
            $this->store->save($s);

            return $result;
        });
    }
}
