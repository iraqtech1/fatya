<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_meta', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedBigInteger('revision')->default(0);
            $t->json('settings');
        });
        foreach (['categories', 'companies', 'products', 'customers', 'suppliers', 'orders', 'tickets', 'imports', 'events', 'slides', 'middle_slides'] as $table) {
            Schema::create($table, function (Blueprint $t) use ($table) {
                $t->string('id', 100)->primary();
                $t->unsignedInteger('position')->default(0);
                $t->json('payload');
                if ($table === 'customers') {
                    $t->string('phone', 20)->nullable()->unique();
                    $t->string('email', 254)->nullable()->unique();
                }
                if (in_array($table, ['orders', 'tickets'])) {
                    $t->string('customer_id', 100)->index();
                    $t->foreign('customer_id')->references('id')->on('customers');
                }
                if ($table === 'orders') {
                    $t->string('reference', 100);
                    $t->unique(['customer_id', 'reference']);
                }
            });
        }
        Schema::create('customer_credentials', function (Blueprint $t) {
            $t->string('customer_id', 100)->primary();
            $t->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $t->string('password');
        });
        Schema::create('auth_tokens', function (Blueprint $t) {
            $t->char('token', 64)->primary();
            $t->string('role', 10);
            $t->string('customer_id', 100)->nullable()->index();
            $t->unsignedBigInteger('expires')->index();
        });
        Schema::create('otp_challenges', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->json('payload');
            $t->unsignedBigInteger('expires')->index();
        });
        Schema::create('inventory_cards', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('product_id', 100);
            $t->foreign('product_id')->references('id')->on('products');
            $t->double('denomination');
            $t->char('code_hash', 64)->unique();
            $t->text('encrypted_code');
            $t->string('serial', 256)->nullable()->collation('utf8mb4_bin')->unique();
        });
        DB::table('store_meta')->insert(['id' => 1, 'revision' => 0, 'settings' => json_encode(['name' => 'فاتيا', 'lowStock' => 5, 'supportEmail' => '', 'supportPhone' => '', 'privacyText' => '', 'aboutText' => ''])]);
    }

    public function down(): void
    {
        foreach (['inventory_cards', 'otp_challenges', 'auth_tokens', 'customer_credentials', 'orders', 'tickets', 'customers', 'products', 'categories', 'companies', 'suppliers', 'imports', 'events', 'slides', 'middle_slides', 'store_meta'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
