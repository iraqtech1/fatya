<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProcurementAudit
{
    public static function actor(): string
    {
        return request()->attributes->get('adminAccess')['username'] ?? (string) config('fatya.admin_user', 'admin');
    }

    public static function record(string $action, ?string $subject, array $details): void
    {
        // Callers supply only allowlisted non-secret metadata, never rows or provider payloads.
        DB::table('procurement_audit')->insert(['actor' => self::actor(), 'action' => $action, 'subject' => $subject, 'details' => Store::json($details), 'created' => time()]);
    }
}
