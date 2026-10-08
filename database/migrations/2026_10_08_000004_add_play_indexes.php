<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The dashboard and Logs page filter and sort by started_at; pruning deletes by it
        Schema::table('stream_logs', function (Blueprint $table) {
            $table->index('started_at');
        });

        // The connection-limit check runs on every play: one user's recent sessions
        Schema::table('active_sessions', function (Blueprint $table) {
            $table->index(['user_id', 'last_activity_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stream_logs', function (Blueprint $table) {
            $table->dropIndex(['started_at']);
        });

        // MySQL may have dropped the plain user_id index the foreign key used, in favour of the
        // composite one: drop the FK first, then the index, then add the FK back
        Schema::table('active_sessions', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'last_activity_at']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }
};
