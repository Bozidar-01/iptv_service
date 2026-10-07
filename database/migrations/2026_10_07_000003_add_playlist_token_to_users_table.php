<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('playlist_token', 64)->nullable()->unique()->after('max_connections');
        });

        DB::table('users')->whereNull('playlist_token')->pluck('id')->each(function ($id) {
            DB::table('users')->where('id', $id)->update(['playlist_token' => Str::random(32)]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['playlist_token']);
            $table->dropColumn('playlist_token');
        });
    }
};
