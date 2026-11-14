<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NotificationCampaigns
{
    public static function target(mixed $path): string
    {
        if (! is_string($path) || ! preg_match('~^/(?:app|cards|orders|account|support|)(?:\?order=[A-Za-z0-9_-]{1,100})?$~D', $path)) {
            throw new ApiError('اختر رابطاً داخلياً صالحاً دون عنوان خارجي', 422);
        }

        return $path;
    }

    public function listing(): array
    {
        $rows = DB::table('notification_campaigns')->orderByDesc('created')->limit(100)->get()->map(function ($row) {
            $counts = DB::table('notification_campaign_recipients')->where('campaign_id', $row->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
            $push = DB::table('customer_notifications')->where('campaign_id', $row->id)->selectRaw('push_status, COUNT(*) as total')->groupBy('push_status')->pluck('total', 'push_status');

            return ['id' => $row->id, 'version' => (int) $row->version, 'title' => $row->title, 'body' => $row->body, 'image' => $row->image, 'targetPath' => $row->target_path, 'audience' => $row->audience, 'push' => (bool) $row->push, 'status' => $row->status, 'creator' => $row->creator, 'reviewer' => $row->reviewer, 'createdAt' => date(DATE_ATOM, $row->created), 'sendAt' => $row->send_at ? date(DATE_ATOM, $row->send_at) : null, 'recipients' => $counts, 'pushResults' => $push, 'pushAttempts' => (int) DB::table('customer_notifications')->where('campaign_id', $row->id)->sum('push_attempts'), 'readCount' => DB::table('customer_notifications')->where('campaign_id', $row->id)->where('unread', false)->count()];
        })->all();

        return ['campaigns' => $rows, 'rules' => $this->rules(), 'expiryAlerts' => DB::table('inventory_expiry_alerts')->orderByDesc('created')->limit(100)->get()->map(fn ($row) => ['id' => $row->id, 'cardId' => $row->card_id, 'productId' => $row->product_id, 'batchId' => $row->batch_id, 'expiresOn' => $row->expires_on, 'createdAt' => date(DATE_ATOM, $row->created), 'customerNotified' => $row->notification_id !== null])->all()];
    }

    public function save(array $input): array
    {
        $title = CatalogEditor::text($input['title'] ?? '', 120);
        $body = CatalogEditor::text($input['body'] ?? '', 2000);
        $image = CatalogEditor::text($input['image'] ?? '', 2048);
        CatalogEditor::image($image);
        $target = self::target($input['targetPath'] ?? '/app');
        $audience = $input['audience'] ?? 'all';
        $customers = $input['customerIds'] ?? [];
        if (! $title || ! $body || ! in_array($audience, ['all', 'selected'], true) || ! is_array($customers) || ! array_is_list($customers) || count($customers) > 10000 || count(array_filter($customers, 'is_string')) !== count($customers) || ! is_bool($input['push'] ?? null)) {
            throw new ApiError('بيانات الإشعار أو الجمهور غير صحيحة', 422);
        }
        if ($audience === 'selected') {
            app(AdminAccess::class)->require('customers.view');
            if (! $customers) {
                throw new ApiError('اختر زبوناً واحداً على الأقل', 422);
            }
        }

        return DB::transaction(function () use ($input, $title, $body, $image, $target, $audience, $customers) {
            $state = app(Store::class)->read(true);
            $known = array_map('strval', array_column($state['customers'], 'id'));
            if (array_diff($customers, $known)) {
                throw new ApiError('أحد حسابات الجمهور غير موجود', 422);
            }
            $id = $input['id'] ?? (string) Str::uuid();
            if (! is_string($id) || ! Str::isUuid($id)) {
                throw new ApiError('معرف الحملة غير صحيح', 422);
            }
            $old = DB::table('notification_campaigns')->where('id', $id)->lockForUpdate()->first();
            if (($input['version'] ?? 0) !== (int) ($old?->version ?? 0) || ($old && ! in_array($old->status, ['draft', 'reviewed'], true))) {
                throw new ApiError('الحملة تغيرت أو أُرسلت؛ حدّث القائمة', 409);
            }
            DB::table('notification_campaigns')->updateOrInsert(['id' => $id], ['title' => $title, 'body' => $body, 'image' => $image ?: null, 'target_path' => $target, 'audience' => $audience, 'encrypted_customers' => Crypt::encryptString(Store::json(array_values(array_unique($customers)))), 'push' => $input['push'], 'status' => 'draft', 'creator' => $old?->creator ?? ProcurementAudit::actor(), 'reviewer' => null, 'reviewed' => null, 'send_at' => null, 'version' => (int) ($old?->version ?? 0) + 1, 'created' => $old?->created ?? time()]);
            ProcurementAudit::record('notifications.draft_saved', $id, ['version' => (int) ($old?->version ?? 0) + 1]);

            return ['id' => $id, 'status' => 'draft'];
        });
    }

    public function transition(array $input, bool $send): array
    {
        if (! is_string($input['id'] ?? null) || ! Str::isUuid($input['id']) || ! is_int($input['version'] ?? null) || (isset($input['sendAt']) && ! is_string($input['sendAt']))) {
            throw new ApiError('معرف الحملة أو موعدها غير صحيح', 422);
        }

        return DB::transaction(function () use ($input, $send) {
            $state = app(Store::class)->read(true);
            $row = DB::table('notification_campaigns')->where('id', $input['id'] ?? '')->lockForUpdate()->first();
            if (! $row || (int) $row->version !== ($input['version'] ?? null) || $row->status !== ($send ? 'reviewed' : 'draft')) {
                throw new ApiError('حالة الحملة أو إصدارها تغير؛ حدّث القائمة', 409);
            }
            if (! $send) {
                $customers = $row->audience === 'all' ? array_values(array_column(array_filter($state['customers'], fn ($c) => ($c['active'] ?? true) === true), 'id')) : json_decode(Crypt::decryptString($row->encrypted_customers), true);
                if (count($customers) > 10000) {
                    throw new ApiError('حد جمهور الحملة عشرة آلاف زبون', 422);
                }
                DB::table('notification_campaign_recipients')->where('campaign_id', $row->id)->delete();
                foreach (array_chunk($customers, 200) as $chunk) {
                    DB::table('notification_campaign_recipients')->insert(array_map(fn ($id) => ['campaign_id' => $row->id, 'customer_id' => (string) $id, 'status' => 'pending'], $chunk));
                }
            }
            $at = time();
            if ($send && ! empty($input['sendAt'])) {
                $at = app(InventoryReports::class)->boundary($input['sendAt']);
                if ($at < time() || $at > time() + 366 * 86400) {
                    throw new ApiError('حدد موعداً مستقبلياً خلال سنة بتوقيت بغداد', 422);
                }
            }
            DB::table('notification_campaigns')->where('id', $row->id)->update(['status' => $send ? 'scheduled' : 'reviewed', 'version' => $row->version + 1, ...$send ? ['send_at' => $at] : ['reviewer' => ProcurementAudit::actor(), 'reviewed' => time()]]);
            ProcurementAudit::record($send ? 'notifications.scheduled' : 'notifications.reviewed', $row->id, ['version' => $row->version + 1, ...$send ? ['sendAtUTC' => date(DATE_ATOM, $at)] : []]);

            return ['id' => $row->id, 'status' => $send ? 'scheduled' : 'reviewed'];
        });
    }

    /** Called by the scheduler. Creates internal messages only; PushDelivery handles opt-in subscriptions. */
    public function run(int $limit = 200): array
    {
        $counts = ['created' => 0, 'skipped' => 0];
        foreach (DB::table('notification_campaigns')->whereIn('status', ['scheduled', 'sending'])->where('send_at', '<=', time())->orderBy('send_at')->limit(10)->pluck('id') as $id) {
            try {
                $part = DB::transaction(function () use ($id, $limit) {
                    $state = app(Store::class)->read(true);
                    $row = DB::table('notification_campaigns')->where('id', $id)->lockForUpdate()->first();
                    if (! $row || ! in_array($row->status, ['scheduled', 'sending'], true)) {
                        return ['created' => 0, 'skipped' => 0];
                    }
                    $customers = collect($state['customers'])->keyBy('id');
                    $counts = ['created' => 0, 'skipped' => 0];
                    foreach (DB::table('notification_campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->limit($limit)->get() as $recipient) {
                        $customer = $customers->get($recipient->customer_id);
                        $notification = null;
                        if ($customer && ($customer['active'] ?? true)) {
                            $notification = app(CustomerNotifications::class)->send($recipient->customer_id, $row->title, $row->body, 'campaign-'.$id, ['image' => $row->image, 'targetPath' => $row->target_path, 'campaignId' => $id, 'push' => (bool) $row->push]);
                        }
                        $counts[$notification ? 'created' : 'skipped']++;
                        DB::table('notification_campaign_recipients')->where('campaign_id', $id)->where('customer_id', $recipient->customer_id)->update(['status' => $notification ? 'created' : 'skipped', 'notification_id' => $notification, 'processed' => time()]);
                    }
                    $pending = DB::table('notification_campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->exists();
                    DB::table('notification_campaigns')->where('id', $id)->update(['status' => $pending ? 'sending' : 'sent', 'finished' => $pending ? null : time()]);
                    if ($counts['created']) {
                        DB::table('store_meta')->where('id', 1)->increment('revision');
                    }

                    return $counts;
                });
            } catch (\Throwable $error) {
                DB::table('notification_campaigns')->where('id', $id)->whereIn('status', ['scheduled', 'sending'])->update(['status' => 'failed']);
                throw $error;
            }
            foreach ($counts as $key => $count) {
                $counts[$key] += $part[$key];
            }
        }

        return $counts;
    }

    public function rules(): array
    {
        return (array) (DB::table('notification_rules')->where('id', 1)->first() ?? (object) ['id' => 1, 'version' => 0, 'expiry_enabled' => false, 'expiry_days' => 7, 'repeat_days' => null, 'notify_customer' => false, 'push' => false, 'last_run' => null, 'last_result' => null]);
    }

    public function saveRules(array $input): array
    {
        if (! is_bool($input['expiry_enabled'] ?? null) || ! is_bool($input['notify_customer'] ?? null) || ! is_bool($input['push'] ?? null) || ! is_int($input['expiry_days'] ?? null) || $input['expiry_days'] < 1 || $input['expiry_days'] > 365 || (isset($input['repeat_days']) && (! is_int($input['repeat_days']) || $input['repeat_days'] < 1 || $input['repeat_days'] > 365))) {
            throw new ApiError('سياسة تنبيه الانتهاء غير صحيحة', 422);
        }

        return DB::transaction(function () use ($input) {
            DB::table('notification_rules')->insertOrIgnore(['id' => 1]);
            $row = DB::table('notification_rules')->where('id', 1)->lockForUpdate()->first();
            if (($input['version'] ?? null) !== (int) $row->version) {
                throw new ApiError('سياسة التنبيه تغيرت؛ حدّث القائمة', 409);
            }
            DB::table('notification_rules')->where('id', 1)->update(array_intersect_key($input, array_flip(['expiry_enabled', 'expiry_days', 'repeat_days', 'notify_customer', 'push'])) + ['version' => $row->version + 1]);
            ProcurementAudit::record('notifications.expiry_policy', null, array_intersect_key($input, array_flip(['expiry_enabled', 'expiry_days', 'repeat_days', 'notify_customer', 'push'])));

            return $this->rules();
        });
    }

    public function expiry(): array
    {
        $rules = $this->rules();
        $count = 0;
        if (! $rules['expiry_enabled']) {
            return ['enabled' => false, 'created' => 0];
        }
        $today = now('Asia/Baghdad');
        $end = $today->addDays($rules['expiry_days'])->toDateString();
        $ids = DB::table('inventory_cards')->whereNotNull('expires_on')->where('expires_on', '<=', $end)->whereNull('withdrawal_id')->whereNotExists(function ($query) use ($rules) {
            $query->selectRaw('1')->from('inventory_expiry_alerts')->whereColumn('card_id', 'inventory_cards.id')->whereColumn('inventory_expiry_alerts.expires_on', 'inventory_cards.expires_on');
            if ($rules['repeat_days']) {
                $query->where('created', '>', time() - $rules['repeat_days'] * 86400);
            }
        })->orderBy('id')->limit(500)->pluck('id');
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $rules) {
                $state = app(Store::class)->read(true);
                if (! DB::table('notification_rules')->where('id', 1)->where('version', $rules['version'])->where('expiry_enabled', true)->exists()) {
                    return 0;
                }
                $card = DB::table('inventory_cards')->where('id', $id)->lockForUpdate()->first();
                if (! $card || $card->withdrawal_id || ! $card->expires_on) {
                    return 0;
                }
                $day = now('Asia/Baghdad')->toDateString();
                $last = DB::table('inventory_expiry_alerts')->where('card_id', $id)->where('expires_on', $card->expires_on)->max('created');
                if ($last && (! $rules['repeat_days'] || $last > time() - $rules['repeat_days'] * 86400)) {
                    return 0;
                }
                $bucket = $rules['repeat_days'] ? 'repeat-'.$day : 'once';
                $key = hash('sha256', $card->id.':'.$card->expires_on.':'.$bucket);
                if (DB::table('inventory_expiry_alerts')->where('id', $key)->exists()) {
                    return 0;
                }
                $notification = null;
                if ($rules['notify_customer'] && $card->sold_order_id) {
                    $order = collect($state['orders'])->firstWhere('id', $card->sold_order_id);
                    if ($order && DB::table('order_fulfillments')->where('order_id', $order['id'])->where('state', 'delivered')->exists()) {
                        $notification = app(CustomerNotifications::class)->send($order['customerId'], 'تنبيه صلاحية بطاقة مشتراة', 'تاريخ صلاحية إحدى بطاقات طلبك هو '.$card->expires_on.'؛ راجع تفاصيل طلبك.', 'expiry-'.$key, ['targetPath' => '/orders?order='.$order['id'], 'push' => (bool) $rules['push']]);
                    }
                }
                DB::table('inventory_expiry_alerts')->insert(['id' => $key, 'card_id' => $card->id, 'product_id' => $card->product_id, 'batch_id' => $card->batch_id, 'expires_on' => $card->expires_on, 'notification_id' => $notification, 'created' => time()]);
                if ($notification) {
                    DB::table('store_meta')->where('id', 1)->increment('revision');
                }

                return 1;
            });
        }
        DB::table('notification_rules')->where('id', 1)->update(['last_run' => time(), 'last_result' => 'created:'.$count]);

        return ['enabled' => true, 'created' => $count];
    }
}
