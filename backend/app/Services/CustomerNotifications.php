<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CustomerNotifications
{
    public function welcome(string $customer): void
    {
        foreach ([
            ['أهلًا بك في فاتيا', 'تصفّح بطاقات الرصيد والألعاب واختر الفئة التي تناسبك'],
            ['بطاقاتك المفضّلة بانتظارك', 'اكتشف البطاقات المتوفرة من قسم شراء بطاقة'],
            ['الدعم موجود لمساعدتك', 'يمكنك إرسال استفسارك ومتابعة التذكرة من صفحة الدعم'],
        ] as $index => [$title, $body]) {
            $this->send($customer, $title, $body, 'welcome-'.$index);
        }
    }

    public function send(string $customer, string $title, string $body, ?string $reference = null, array $metadata = []): string
    {
        if ($reference && DB::table('customer_notifications')->where('customer_id', $customer)->where('reference', $reference)->exists()) {
            return (string) DB::table('customer_notifications')->where('customer_id', $customer)->where('reference', $reference)->value('id');
        }
        $id = (string) Str::uuid();
        $key = $reference !== null ? hash('sha256', $customer.':'.$reference) : null;
        DB::table('customer_notifications')->insertOrIgnore(['id' => $id, 'customer_id' => $customer, 'title' => $title, 'body' => $body, 'reference' => $reference, 'dedupe_key' => $key, 'image' => $metadata['image'] ?? null, 'target_path' => $metadata['targetPath'] ?? null, 'kind' => $metadata['kind'] ?? 'information', 'campaign_id' => $metadata['campaignId'] ?? null, 'created' => (int) (microtime(true) * 1000), 'unread' => true, 'push_status' => ($metadata['push'] ?? true) && app(Takeed::class)->enabled() ? 'pending' : 'none']);

        return $key ? (string) DB::table('customer_notifications')->where('dedupe_key', $key)->value('id') : $id;
    }

    public function forCustomer(string $customer): array
    {
        return DB::table('customer_notifications')->where('customer_id', $customer)->orderByDesc('created')->orderByDesc('id')->limit(100)->get()->map(fn ($n) => $this->dto($n))->all();
    }

    public function find(string $customer, string $id): array
    {
        if (! Str::isUuid($id)) {
            throw new ApiError('الإشعار غير موجود', 404);
        }
        $row = DB::table('customer_notifications')->where('customer_id', $customer)->where('id', $id)->first();
        if (! $row) {
            throw new ApiError('الإشعار غير موجود', 404);
        }

        return $this->dto($row);
    }

    private function dto(object $n): array
    {
        return [
            'id' => $n->id, 'title' => $n->title, 'body' => $n->body,
            'time' => Carbon::createFromTimestampMs($n->created)->setTimezone('Asia/Baghdad')->format('Y-m-d H:i:s'),
            'unread' => (bool) $n->unread, 'image' => $n->image, 'targetPath' => $n->target_path, 'kind' => $n->kind, 'readAt' => $n->read_at ? Carbon::createFromTimestampMs($n->read_at)->utc()->toIso8601String() : null,
        ];
    }

    public function markRead(string $customer, mixed $ids): array
    {
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > 100 || array_filter($ids, fn ($id) => ! is_string($id) || ! Str::isUuid($id))) {
            throw new ApiError('إشعارات غير صحيحة');
        }
        $ids = array_values(array_unique($ids));
        $query = DB::table('customer_notifications')->where('customer_id', $customer)->whereIn('id', $ids);
        if ((clone $query)->count() !== count($ids)) {
            throw new ApiError('الإشعار غير موجود', 404);
        }
        if ($query->where('unread', true)->update(['unread' => false, 'read_at' => (int) (microtime(true) * 1000)])) {
            DB::table('store_meta')->where('id', 1)->increment('revision');
        }

        return ['notifications' => $this->forCustomer($customer), 'revision' => (int) DB::table('store_meta')->where('id', 1)->value('revision')];
    }
}
