<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('customer_id', 100);
            $t->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $t->string('title', 200);
            $t->text('body');
            $t->string('reference', 150)->nullable();
            $t->unsignedBigInteger('created');
            $t->boolean('unread')->default(true);
            $t->unique(['customer_id', 'reference']);
            $t->index(['customer_id', 'created']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
    }
};
