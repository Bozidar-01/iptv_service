<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Chosen by the admin: play this channel's stream when ours stops working
            $table->foreignId('backup_channel_id')->nullable()->after('alert_on_down')->constrained('channels')->nullOnDelete();
            // Set by the stream check while our stream is down and a backup plays instead
            $table->foreignId('using_backup_id')->nullable()->after('backup_channel_id')->constrained('channels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('using_backup_id');
            $table->dropConstrainedForeignId('backup_channel_id');
        });
    }
};
