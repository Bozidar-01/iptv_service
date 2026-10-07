<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // Some streams only play with the player identity / referring page from the playlist
            $table->string('http_user_agent', 512)->nullable()->after('tvg_id');
            $table->string('http_referrer', 1024)->nullable()->after('http_user_agent');
            $table->string('stream_error', 20)->nullable()->after('stream_checked_at');
        });

        Schema::table('stream_alerts', function (Blueprint $table) {
            $table->string('detail', 50)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('stream_alerts', function (Blueprint $table) {
            $table->dropColumn('detail');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['http_user_agent', 'http_referrer', 'stream_error']);
        });
    }
};
