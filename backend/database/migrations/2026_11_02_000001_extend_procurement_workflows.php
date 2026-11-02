<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_category_codes', function (Blueprint $t) {
            $t->string('code', 40)->primary();
            $t->string('product_id', 100);
            $t->decimal('denomination', 20, 3);
            $t->foreign('product_id')->references('id')->on('products');
        });
        Schema::create('procurement_settings', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->boolean('approval_required')->default(false);
            $t->unsignedInteger('version')->default(0);
        });
        DB::table('procurement_settings')->insert(['id' => 1, 'approval_required' => false, 'version' => 0]);
        Schema::create('stock_submissions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('request_id', 100)->unique();
            $t->char('request_hash', 64);
            $t->string('status', 20);
            $t->unsignedInteger('version')->default(0);
            $t->json('summary');
            $t->json('catalog_evidence');
            $t->text('reason')->nullable();
            $t->unsignedBigInteger('created');
            $t->unsignedBigInteger('updated');
        });
        Schema::create('stock_submission_chunks', function (Blueprint $t) {
            $t->uuid('submission_id');
            $t->unsignedInteger('part');
            $t->longText('encrypted_cards');
            $t->primary(['submission_id', 'part']);
            $t->foreign('submission_id')->references('id')->on('stock_submissions')->cascadeOnDelete();
        });
        Schema::create('procurement_mutations', function (Blueprint $t) {
            $t->string('request_id', 100)->primary();
            $t->char('hash', 64);
            $t->json('result');
        });
        Schema::create('digital_connections', function (Blueprint $t) {
            $t->string('provider', 20)->primary();
            $t->text('credential')->nullable();
            $t->boolean('active')->default(false);
            $t->unsignedInteger('version')->default(0);
            $t->decimal('balance', 20, 3)->nullable();
            $t->unsignedBigInteger('balance_updated')->nullable();
            $t->json('premium_provinces')->nullable();
        });
        Schema::create('digital_executions', function (Blueprint $t) {
            $t->string('order_id', 100)->primary();
            $t->string('provider', 20);
            $t->uuid('offer_id');
            $t->unsignedInteger('connection_version');
            $t->string('state', 30)->default('awaiting_payment')->index();
            $t->longText('encrypted_input');
            $t->longText('encrypted_evidence')->nullable();
            $t->char('request_hash', 64);
            $t->string('provider_reference', 150)->nullable();
            $t->decimal('quoted_cost', 20, 3);
            $t->decimal('actual_cost', 20, 3)->nullable();
            $t->string('reason', 100)->nullable();
            $t->unsignedBigInteger('updated');
            $t->unique(['provider', 'provider_reference']);
            $t->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        foreach (['digital_executions', 'digital_connections', 'procurement_mutations', 'stock_submission_chunks', 'stock_submissions', 'procurement_settings', 'stock_category_codes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
