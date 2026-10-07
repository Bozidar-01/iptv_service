<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // XMLTV guide files (TV guide page)
        Schema::create('epg_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->unsignedInteger('channel_count')->default(0);
            $table->unsignedInteger('programme_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });

        // Channels listed in a guide, used to match our channels to it
        Schema::create('epg_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epg_source_id')->constrained()->cascadeOnDelete();
            $table->string('xmltv_id');
            $table->string('name');
            $table->string('name_key')->index();
            $table->unique(['epg_source_id', 'xmltv_id']);
        });

        Schema::create('epg_programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('epg_source_id')->constrained()->cascadeOnDelete();
            $table->string('xmltv_id');
            $table->dateTime('start');
            $table->dateTime('stop');
            $table->string('title', 500);
            $table->text('description')->nullable();
            $table->index(['epg_source_id', 'xmltv_id', 'start']);
        });

        Schema::table('channels', function (Blueprint $table) {
            // Guide channel this channel shows: matched automatically, or set by the admin (epg_manual)
            $table->foreignId('epg_source_id')->nullable()->after('tvg_id')->constrained()->nullOnDelete();
            $table->string('epg_id')->nullable()->after('epg_source_id');
            $table->boolean('epg_manual')->default(false)->after('epg_id');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('epg_source_id');
            $table->dropColumn(['epg_id', 'epg_manual']);
        });
        Schema::dropIfExists('epg_programmes');
        Schema::dropIfExists('epg_channels');
        Schema::dropIfExists('epg_sources');
    }
};
