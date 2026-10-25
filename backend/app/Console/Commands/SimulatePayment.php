<?php

namespace App\Console\Commands;

use App\Services\OrderFulfillment;
use App\Services\VerifiedPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SimulatePayment extends Command
{
    protected $signature = 'fatya:simulate-payment {order} {--amount= : Decimal amount in IQD} {--currency=IQD} {--provider=Qi} {--transaction=} {--event=}';

    protected $description = 'Local/test ONLY: simulate a trusted gateway receipt without charging money';

    public function handle(OrderFulfillment $fulfillment): int
    {
        if (PHP_SAPI !== 'cli' || ! app()->environment(['local', 'testing']) || ! config('fatya.allow_payment_simulation')) {
            $this->error('Payment simulation is disabled. It is never available in production or over HTTP.');

            return self::FAILURE;
        }
        if (! is_string($this->option('amount')) || $this->option('amount') === '') {
            $this->error('Provide --amount explicitly. No real payment will be collected.');

            return self::FAILURE;
        }
        $transaction = $this->option('transaction') ?: 'local-'.Str::uuid();
        $result = $fulfillment->confirm(new VerifiedPayment($this->argument('order'), $this->option('provider'), $transaction, $this->option('event') ?: $transaction, $this->option('amount'), $this->option('currency')));
        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
