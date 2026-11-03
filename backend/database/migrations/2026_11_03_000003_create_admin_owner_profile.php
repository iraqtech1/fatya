<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_owner_profile', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('name', 80)->default('مالك النظام');
            $table->string('email', 254)->nullable();
            $table->string('about', 2000)->default('');
            $table->mediumText('image')->nullable();
            $table->string('password')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('updated_at')->nullable();
        });
        DB::table('admin_owner_profile')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_owner_profile');
    }
};
