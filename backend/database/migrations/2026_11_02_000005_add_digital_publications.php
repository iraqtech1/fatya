<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_publications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->char('identity', 64)->unique();
            $t->string('product_id', 100)->index();
            $t->string('connection', 20);
            $t->string('provider_id', 100);
            $t->json('snapshot');
            $t->decimal('retail', 20, 3);
            $t->boolean('active')->default(false);
            $t->unsignedInteger('version')->default(1);
            $t->unsignedBigInteger('updated');
        });
        Schema::create('digital_manual_payments', function (Blueprint $t) {
            $t->string('order_id', 100)->primary();
            $t->string('request_id', 100)->unique();
            $t->string('receipt_reference', 100)->unique();
            $t->decimal('amount', 20, 3);
            $t->string('actor', 100);
            $t->text('note');
            $t->unsignedBigInteger('created');
            $t->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_manual_payments');
        Schema::dropIfExists('digital_publications');
    }
};
