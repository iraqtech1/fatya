<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_order_previews', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('hash', 64);
            $t->unsignedBigInteger('revision');
            $t->longText('encrypted_draft');
            $t->unsignedBigInteger('expires');
        });
        Schema::create('stock_order_preview_chunks', function (Blueprint $t) {
            $t->uuid('preview_id');
            $t->unsignedInteger('part');
            $t->longText('encrypted_cards');
            $t->primary(['preview_id', 'part']);
            $t->foreign('preview_id')->references('id')->on('stock_order_previews')->cascadeOnDelete();
        });
        Schema::create('stock_batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('request_id', 100)->unique();
            $t->char('request_hash', 64);
            $t->json('summary');
            $t->unsignedBigInteger('created');
        });
        Schema::table('inventory_cards', function (Blueprint $t) {
            $t->decimal('unit_cost', 20, 3)->nullable();
            $t->date('expires_on')->nullable()->index();
            $t->uuid('batch_id')->nullable()->index();
        });
        Schema::create('digital_catalog', function (Blueprint $t) {
            $t->string('connection', 20)->primary();
            $t->json('snapshot');
            $t->unsignedBigInteger('synced');
        });
        Schema::create('digital_offers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('product_id', 100);
            $t->unsignedInteger('denom_index');
            $t->string('connection', 20);
            $t->string('provider_id', 100);
            $t->json('snapshot');
            $t->decimal('retail', 20, 3);
            $t->boolean('active')->default(false);
            $t->unsignedBigInteger('updated');
            $t->unique(['product_id', 'denom_index']);
            $t->foreign('product_id')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_offers');
        Schema::dropIfExists('digital_catalog');
        Schema::table('inventory_cards', function (Blueprint $t) {
            $t->dropColumn(['unit_cost', 'expires_on', 'batch_id']);
        });
        Schema::dropIfExists('stock_batches');
        Schema::dropIfExists('stock_order_preview_chunks');
        Schema::dropIfExists('stock_order_previews');
    }
};
