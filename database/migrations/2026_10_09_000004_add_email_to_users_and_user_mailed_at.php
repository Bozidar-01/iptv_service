<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Optional: where the subscriber gets expiry reminders. Not unique: a family may share one address.
            $table->string('email')->nullable();
            // Language of those emails (en / mk); empty = the panel's default
            $table->string('locale', 5)->nullable();
        });

        Schema::table('stream_alerts', function (Blueprint $table) {
            // When the subscriber was emailed about this reminder (SubscriptionReminderService); null = not yet / no address
            $table->timestamp('user_mailed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stream_alerts', function (Blueprint $table) {
            $table->dropColumn('user_mailed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['email', 'locale']);
        });
    }
};
