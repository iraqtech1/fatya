<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('digital_executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('journal_version')->nullable();
        });
        Schema::create('digital_dispatches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('order_id', 100);
            $table->string('provider', 20);
            $table->unsignedInteger('connection_version');
            $table->string('endpoint', 100);
            $table->string('outcome', 40);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->char('response_hash', 64)->nullable();
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            // A crash between recording intent and sending requires reconciliation, never replay.
            $table->unique(['order_id', 'endpoint']);
            $table->index(['provider', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_dispatches');
        Schema::table('digital_executions', function (Blueprint $table) {
            $table->dropColumn('journal_version');
        });
    }
};
