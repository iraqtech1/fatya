<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_staff', function (Blueprint $table) {
            $table->string('email', 254)->nullable()->unique();
            $table->string('about', 2000)->default('');
            $table->mediumText('image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('admin_staff', function (Blueprint $table) {
            $table->dropUnique(['email']);
            $table->dropColumn(['email', 'about', 'image']);
        });
    }
};
