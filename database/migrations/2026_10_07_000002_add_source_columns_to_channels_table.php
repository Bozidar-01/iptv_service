<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('source_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('external_key', 64)->nullable()->after('source_id');
            $table->string('tvg_id')->nullable()->after('logo_url');
            $table->unsignedInteger('sort_order')->default(0)->after('tvg_id');
            $table->string('stream_url', 2048)->change();
            $table->string('logo_url', 2048)->nullable()->change();

            $table->unique(['source_id', 'external_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            // MySQL uses the unique index for the FK, so drop the FK first
            $table->dropForeign(['source_id']);
            $table->dropUnique(['source_id', 'external_key']);
            $table->dropColumn('source_id');
            $table->dropColumn(['external_key', 'tvg_id', 'sort_order']);
            $table->string('stream_url')->change();
            $table->string('logo_url')->nullable()->change();
        });
    }
};
