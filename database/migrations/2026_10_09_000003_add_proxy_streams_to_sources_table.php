<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // Play this source's channels through the server (StreamProxyService): viewers never see the provider address
            $table->boolean('proxy_streams')->default(false)->after('check_streams');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('proxy_streams');
        });
    }
};
