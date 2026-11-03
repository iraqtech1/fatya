<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80)->unique();
            $t->string('description', 500)->default('');
            $t->boolean('unrestricted')->default(false);
            $t->json('permissions');
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
        });
        Schema::create('admin_staff', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('name', 80);
            $t->string('username', 64)->unique();
            $t->string('password');
            $t->uuid('role_id');
            $t->foreign('role_id')->references('id')->on('admin_roles');
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(1);
            $t->timestamp('last_login_at')->nullable();
            $t->timestamp('deleted_at')->nullable();
            $t->timestamps();
        });
        Schema::table('auth_tokens', function (Blueprint $t) {
            $t->uuid('staff_id')->nullable()->index();
            $t->foreign('staff_id')->references('id')->on('admin_staff');
        });
        Schema::create('admin_access_audit', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('actor_id', 100);
            $t->string('actor', 80);
            $t->string('action', 100);
            $t->string('subject', 100)->nullable();
            $t->json('details');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_access_audit');
        Schema::table('auth_tokens', function (Blueprint $t) {
            $t->dropForeign(['staff_id']);
            $t->dropColumn('staff_id');
        });
        Schema::dropIfExists('admin_staff');
        Schema::dropIfExists('admin_roles');
    }
};
