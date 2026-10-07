<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // url | file | receiver (satellite receiver behind a NeboTV relay)
            $table->string('type', 20)->default('url')->change();
            $table->text('relay_secret')->nullable()->after('file_path');
            $table->boolean('transcode')->default(false)->after('relay_secret');
            $table->boolean('receiver_online')->nullable()->after('transcode');
            $table->timestamp('receiver_checked_at')->nullable()->after('receiver_online');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['relay_secret', 'transcode', 'receiver_online', 'receiver_checked_at']);
        });

        DB::table('sources')->where('type', 'receiver')->delete();

        Schema::table('sources', function (Blueprint $table) {
            $table->enum('type', ['url', 'file'])->default('url')->change();
        });
    }
};
