<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_fulfillments', function (Blueprint $t) {
            $t->string('order_id', 100)->primary();
            $t->foreign('order_id')->references('id')->on('orders');
            $t->string('state', 24);
            $t->string('payment_status', 24)->default('pending');
            $t->string('provider', 30);
            $t->decimal('amount', 20, 3);
            $t->char('currency', 3)->default('IQD');
            $t->unsignedInteger('quantity');
            $t->unsignedBigInteger('expires')->index();
            $t->unsignedBigInteger('delivered_at')->nullable();
            $t->string('review_reason', 80)->nullable();
        });
        Schema::table('inventory_cards', function (Blueprint $t) {
            $t->string('reserved_order_id', 100)->nullable()->index();
            $t->foreign('reserved_order_id')->references('id')->on('orders');
            $t->string('sold_order_id', 100)->nullable()->index();
            $t->foreign('sold_order_id')->references('id')->on('orders');
            $t->index(['product_id', 'denomination', 'reserved_order_id', 'sold_order_id'], 'available_inventory');
        });
        Schema::create('reservation_items', function (Blueprint $t) {
            $t->string('order_id', 100);
            $t->unsignedBigInteger('card_id');
            $t->foreign('order_id')->references('id')->on('orders');
            $t->foreign('card_id')->references('id')->on('inventory_cards');
            $t->primary(['order_id', 'card_id']);
        });
        Schema::create('delivered_cards', function (Blueprint $t) {
            // A physical card can appear in the delivery ledger only once.
            $t->unsignedBigInteger('card_id')->primary();
            $t->foreign('card_id')->references('id')->on('inventory_cards');
            $t->string('order_id', 100)->index();
            $t->foreign('order_id')->references('id')->on('orders');
            $t->unsignedBigInteger('delivered_at');
        });
        Schema::create('payment_receipts', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('order_id', 100)->index();
            $t->foreign('order_id')->references('id')->on('orders');
            $t->string('provider', 30);
            $t->string('transaction_id', 150)->collation('utf8mb4_bin');
            $t->string('event_id', 150)->collation('utf8mb4_bin');
            $t->decimal('amount', 20, 3);
            $t->char('currency', 3);
            $t->string('outcome', 24);
            $t->string('reason', 80)->nullable();
            $t->unsignedBigInteger('received_at');
            $t->unique(['provider', 'transaction_id']);
            $t->unique(['provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('delivered_cards');
        Schema::dropIfExists('reservation_items');
        Schema::table('inventory_cards', function (Blueprint $t) {
            $t->dropForeign(['reserved_order_id']);
            $t->dropForeign(['sold_order_id']);
            $t->dropIndex('available_inventory');
            $t->dropColumn(['reserved_order_id', 'sold_order_id']);
        });
        Schema::dropIfExists('order_fulfillments');
    }
};
