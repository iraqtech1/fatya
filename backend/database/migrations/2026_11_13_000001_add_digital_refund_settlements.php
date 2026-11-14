<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('digital_refund_settlements', function (Blueprint $t) {
            $t->string('order_id', 100)->primary();
            $t->string('request_id', 100)->unique();
            $t->string('refund_reference', 100)->unique();
            $t->decimal('amount', 18, 3);
            $t->string('actor', 150);
            $t->text('note');
            $t->string('basis', 40);
            $t->unsignedBigInteger('created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_refund_settlements');
    }
};
