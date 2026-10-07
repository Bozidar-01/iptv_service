<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The device a session belongs to (client IP + user agent), so a connection is counted per device:
     * switching channels reuses the device's slot, a second device needs its own.
     */
    public function up(): void
    {
        Schema::table('active_sessions', function (Blueprint $table) {
            $table->string('ip', 45)->nullable()->after('channel_id');
            $table->string('user_agent')->nullable()->after('ip');
        });
    }

    public function down(): void
    {
        Schema::table('active_sessions', function (Blueprint $table) {
            $table->dropColumn(['ip', 'user_agent']);
        });
    }
};
