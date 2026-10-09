<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            // The name keys of the guide channel's other display names, separated by "|" (a channel can have several names)
            $table->text('alt_keys')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->dropColumn('alt_keys');
        });
    }
};
