<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;

class PushDelivery
{
    public function run(int $limit = 30): array
    {
        $provider = app(Takeed::class);
        $counts = ['sent' => 0, 'retry' => 0, 'failed' => 0];
        if (! $provider->enabled()) {
            return $counts;
        }
        $ids = DB::table('customer_notifications')->where('push_status', 'pending')->where('push_after', '<=', time())->orderBy('created')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            // A lease prevents overlapping workers from sending the same row concurrently.
            $row = DB::transaction(function () use ($id) {
                $row = DB::table('customer_notifications')->where('id', $id)->lockForUpdate()->first();
                if (! $row || $row->push_status !== 'pending' || $row->push_after > time()) {
                    return null;
                }
                DB::table('customer_notifications')->where('id', $id)->update(['push_after' => time() + 180, 'push_attempts' => $row->push_attempts + 1]);

                return $row;
            });
            if (! $row) {
                continue;
            }
            try {
                $response = $provider->client()->post('/notifications', [
                    'app_id' => config('fatya.takeed_app_id'),
                    'headings' => ['ar' => 'فاتيا · '.$row->title, 'en' => 'Fatya'],
                    // Detailed order information remains behind the authenticated app.
                    'contents' => ['ar' => 'لديك تحديث جديد في حسابك افتح فاتيا للاطلاع عليه', 'en' => 'You have a new account update. Open Fatya to view it.'],
                    'include_aliases' => ['external_id' => [$provider->alias($row->customer_id)]],
                    'url' => rtrim(config('app.url'), '/').'/',
                    'collapse_id' => $row->id,
                    'data' => ['notification_id' => $row->id],
                    'ttl' => 86400,
                ]);
                if ($response->successful() && is_string($response->json('id'))) {
                    DB::table('customer_notifications')->where('id', $id)->update(['push_status' => 'sent', 'push_provider_id' => $response->json('id')]);
                    $counts['sent']++;

                    continue;
                }
                $permanent = in_array($response->status(), [400, 401, 403, 404, 422]);
            } catch (ConnectionException|\App\Exceptions\ApiError) {
                $permanent = false;
            }
            $failed = $permanent || $row->push_attempts >= 4;
            DB::table('customer_notifications')->where('id', $id)->update(['push_status' => $failed ? 'failed' : 'pending', 'push_after' => time() + min(3600, 60 * (2 ** $row->push_attempts))]);
            $counts[$failed ? 'failed' : 'retry']++;
        }

        return $counts;
    }
}
