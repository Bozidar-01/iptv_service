<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // The best picture the stream offers, read by the stream check from an HLS playlist (null = not known)
            $table->unsignedSmallInteger('quality_height')->nullable();
            $table->unsignedInteger('quality_kbps')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['quality_height', 'quality_kbps']);
        });
    }
};
