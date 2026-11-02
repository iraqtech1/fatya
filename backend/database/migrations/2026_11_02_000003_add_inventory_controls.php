<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_cards', function (Blueprint $t) {
            $t->unsignedInteger('source_line')->nullable();
            $t->unsignedInteger('source_row')->nullable();
            $t->unsignedBigInteger('imported_at')->nullable()->index();
            $t->uuid('withdrawal_id')->nullable()->index();
        });
        Schema::create('inventory_exports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('request_id', 100)->unique();
            $t->char('request_hash', 64);
            $t->string('kind', 20);
            $t->string('status', 20);
            $t->unsignedInteger('version')->default(0);
            $t->string('actor', 100);
            $t->text('reason');
            $t->json('filters');
            $t->unsignedInteger('quantity');
            $t->longText('encrypted_ids');
            $t->unsignedBigInteger('created')->index();
            $t->unsignedBigInteger('expires')->nullable();
            $t->unsignedBigInteger('approved_at')->nullable();
        });
        Schema::create('procurement_audit', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('actor', 100);
            $t->string('action', 60)->index();
            $t->string('subject', 100)->nullable();
            $t->json('details');
            $t->unsignedBigInteger('created')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procurement_audit');
        Schema::dropIfExists('inventory_exports');
        Schema::table('inventory_cards', fn (Blueprint $t) => $t->dropColumn(['source_line', 'source_row', 'imported_at', 'withdrawal_id']));
    }
};
