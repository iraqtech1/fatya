<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_refund_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('order_id', 100)->index();
            $t->string('request_id', 100)->unique();
            $t->string('refund_reference', 100)->unique();
            $t->decimal('amount', 20, 3);
            $t->string('actor', 100);
            $t->text('note');
            $t->string('basis', 40);
            $t->boolean('revenue_recognized');
            $t->unsignedBigInteger('created');
            $t->foreign('order_id')->references('id')->on('orders');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_refund_entries');
    }
};
