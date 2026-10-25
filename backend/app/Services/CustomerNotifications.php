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

    public function send(string $customer, string $title, string $body, ?string $reference = null): void
    {
        if ($reference && DB::table('customer_notifications')->where('customer_id', $customer)->where('reference', $reference)->exists()) {
            return;
        }
        DB::table('customer_notifications')->insert(['id' => (string) Str::uuid(), 'customer_id' => $customer, 'title' => $title, 'body' => $body, 'reference' => $reference, 'created' => (int) (microtime(true) * 1000), 'unread' => true]);
    }

    public function forCustomer(string $customer): array
    {
        return DB::table('customer_notifications')->where('customer_id', $customer)->orderByDesc('created')->orderByDesc('id')->limit(100)->get()->map(fn ($n) => [
            'id' => $n->id, 'title' => $n->title, 'body' => $n->body,
            'time' => Carbon::createFromTimestampMs($n->created)->setTimezone('Asia/Baghdad')->format('Y-m-d H:i'),
            'unread' => (bool) $n->unread,
        ])->all();
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
        if ($query->where('unread', true)->update(['unread' => false])) {
            DB::table('store_meta')->where('id', 1)->increment('revision');
        }

        return ['notifications' => $this->forCustomer($customer), 'revision' => (int) DB::table('store_meta')->where('id', 1)->value('revision')];
    }
}
