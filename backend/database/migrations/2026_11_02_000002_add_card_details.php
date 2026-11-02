<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_cards', fn (Blueprint $t) => $t->longText('encrypted_details')->nullable());
    }

    public function down(): void
    {
        Schema::table('inventory_cards', fn (Blueprint $t) => $t->dropColumn('encrypted_details'));
    }
};
