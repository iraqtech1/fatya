<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $t) {
            $t->string('push_status', 16)->default('none');
            $t->unsignedTinyInteger('push_attempts')->default(0);
            $t->unsignedBigInteger('push_after')->default(0);
            $t->string('push_provider_id', 100)->nullable();
            $t->index(['push_status', 'push_after'], 'notifications_push_due');
        });
    }

    public function down(): void
    {
        Schema::table('customer_notifications', function (Blueprint $t) {
            $t->dropIndex('notifications_push_due');
            $t->dropColumn(['push_status', 'push_attempts', 'push_after', 'push_provider_id']);
        });
    }
};
