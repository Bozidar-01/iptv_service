<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_alerts', function (Blueprint $table) {
            // Subscription reminders (expiring / expired) are about a user
            $table->foreignId('user_id')->nullable()->after('source_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stream_alerts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
