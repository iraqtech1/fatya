<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append-only financial dispatch identity. No tokens, subscriber data, codes or response bodies. */
class DigitalDispatches
{
    public static function begin(string $orderId, string $provider, int $version, string $endpoint): string
    {
        return DB::transaction(function () use ($orderId, $provider, $version, $endpoint) {
            $execution = DB::table('digital_executions')->where('order_id', $orderId)->lockForUpdate()->first();
            $payment = DB::table('order_fulfillments')->where('order_id', $orderId)->first();
            $stage = $endpoint === '/vendor/hold' ? 'hold_started' : 'purchase_started';
            if (! $execution || $execution->provider !== $provider || (int) $execution->connection_version !== $version || $execution->state !== $stage || $payment?->payment_status !== 'confirmed' || $payment->state !== 'processing') {
                throw new ApiError('financial_dispatch_not_authorized', 409);
            }
            $id = (string) Str::uuid();
            try {
                DB::table('digital_dispatches')->insert(['id' => $id, 'order_id' => $orderId, 'provider' => $provider, 'connection_version' => $version, 'endpoint' => $endpoint, 'outcome' => 'dispatch_started', 'started_at' => now()->utc()->format('Y-m-d H:i:s.u')]);
            } catch (UniqueConstraintViolationException) {
                throw new ApiError('financial_dispatch_already_recorded', 409);
            }

            return $id;
        });
    }

    public static function finish(string $id, string $outcome, ?int $status = null, ?string $responseHash = null): void
    {
        DB::table('digital_dispatches')->where('id', $id)->whereNull('finished_at')->update(['outcome' => $outcome, 'http_status' => $status, 'response_hash' => $responseHash, 'finished_at' => now()->utc()->format('Y-m-d H:i:s.u')]);
    }

    public static function forOrders(array $ids): array
    {
        return DB::table('digital_dispatches')->whereIn('order_id', $ids)->orderBy('started_at')->orderBy('id')->get()->groupBy('order_id')->map(fn ($rows) => $rows->map(fn ($row) => ['id' => $row->id, 'endpoint' => $row->endpoint, 'outcome' => $row->outcome, 'httpStatus' => $row->http_status, 'connectionVersion' => (int) $row->connection_version, 'startedAt' => str_replace(' ', 'T', $row->started_at).'Z', 'finishedAt' => $row->finished_at ? str_replace(' ', 'T', $row->finished_at).'Z' : null])->all())->all();
    }
}
