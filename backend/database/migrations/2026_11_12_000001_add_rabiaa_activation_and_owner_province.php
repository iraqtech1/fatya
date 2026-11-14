<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_connections', function (Blueprint $t) {
            $t->string('owner_city', 100)->nullable();
        });
        Schema::table('digital_executions', function (Blueprint $t) {
            $t->unsignedTinyInteger('tracking_version')->default(0);
            $t->unsignedBigInteger('dispatch_started')->nullable();
            $t->unsignedBigInteger('response_received')->nullable();
        });
        Schema::create('digital_catalog_previews', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('provider', 20)->index();
            $t->unsignedInteger('connection_version');
            $t->char('catalog_hash', 64);
            $t->longText('encrypted_snapshot');
            $t->unsignedBigInteger('created');
            $t->boolean('accepted')->default(false);
        });
        Schema::create('rabiaa_activations', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('actor', 150)->index();
            $t->string('request_key', 100);
            $t->char('request_hash', 64);
            $t->char('active_pin_hash', 64)->nullable()->unique();
            $t->string('order_id', 100)->nullable();
            $t->unsignedInteger('connection_version');
            $t->text('encrypted_payload');
            $t->longText('encrypted_response')->nullable();
            $t->string('status', 20);
            $t->text('message');
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedBigInteger('created');
            $t->unsignedBigInteger('finished')->nullable();
            $t->unique(['actor', 'request_key']);
        });
    }

    public function down(): void
    {
        Schema::table('digital_executions', fn (Blueprint $t) => $t->dropColumn(['tracking_version', 'dispatch_started', 'response_received']));
        Schema::dropIfExists('rabiaa_activations');
        Schema::dropIfExists('digital_catalog_previews');
        Schema::table('digital_connections', fn (Blueprint $t) => $t->dropColumn('owner_city'));
    }
};
