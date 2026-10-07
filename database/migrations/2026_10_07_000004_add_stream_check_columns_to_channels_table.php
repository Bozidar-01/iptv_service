<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('stream_status', 10)->nullable()->after('is_active');
            $table->timestamp('stream_checked_at')->nullable()->after('stream_status');
            $table->boolean('auto_disabled')->default(false)->after('stream_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['stream_status', 'stream_checked_at', 'auto_disabled']);
        });
    }
};
