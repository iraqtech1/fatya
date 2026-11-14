<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_notifications', function (Blueprint $t) {
            $t->char('dedupe_key', 64)->nullable()->unique();
            $t->string('image', 2048)->nullable();
            $t->string('target_path', 256)->nullable();
            $t->string('kind', 30)->default('information');
            $t->unsignedBigInteger('read_at')->nullable();
            $t->uuid('campaign_id')->nullable()->index();
        });
        Schema::create('notification_campaigns', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedInteger('version')->default(1);
            $t->string('title', 120);
            $t->text('body');
            $t->string('image', 2048)->nullable();
            $t->string('target_path', 256);
            $t->string('audience', 20);
            $t->longText('encrypted_customers');
            $t->boolean('push')->default(false);
            $t->string('status', 20)->default('draft')->index();
            $t->string('creator', 100);
            $t->string('reviewer', 100)->nullable();
            $t->unsignedBigInteger('created');
            $t->unsignedBigInteger('reviewed')->nullable();
            $t->unsignedBigInteger('send_at')->nullable()->index();
            $t->unsignedBigInteger('finished')->nullable();
        });
        Schema::create('notification_campaign_recipients', function (Blueprint $t) {
            $t->uuid('campaign_id');
            $t->string('customer_id', 100);
            $t->string('status', 20)->default('pending');
            $t->uuid('notification_id')->nullable();
            $t->unsignedBigInteger('processed')->nullable();
            $t->primary(['campaign_id', 'customer_id']);
        });
        Schema::create('notification_rules', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary();
            $t->unsignedInteger('version')->default(0);
            $t->boolean('expiry_enabled')->default(false);
            $t->unsignedSmallInteger('expiry_days')->default(7);
            $t->unsignedSmallInteger('repeat_days')->nullable();
            $t->boolean('notify_customer')->default(false);
            $t->boolean('push')->default(false);
            $t->unsignedBigInteger('last_run')->nullable();
            $t->string('last_result', 200)->nullable();
        });
        Schema::create('inventory_expiry_alerts', function (Blueprint $t) {
            $t->char('id', 64)->primary();
            $t->string('card_id', 100)->index();
            $t->string('product_id', 100);
            $t->string('batch_id', 100)->nullable();
            $t->date('expires_on');
            $t->uuid('notification_id')->nullable();
            $t->unsignedBigInteger('created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_expiry_alerts');
        Schema::dropIfExists('notification_rules');
        Schema::dropIfExists('notification_campaign_recipients');
        Schema::dropIfExists('notification_campaigns');
        Schema::table('customer_notifications', function (Blueprint $t) {
            $t->dropUnique(['dedupe_key']);
            $t->dropIndex(['campaign_id']);
        });
        Schema::table('customer_notifications', fn (Blueprint $t) => $t->dropColumn(['dedupe_key', 'image', 'target_path', 'kind', 'read_at', 'campaign_id']));
    }
};
