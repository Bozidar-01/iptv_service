<?php

use App\Services\ChannelNumberService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel health (reliability, speed, frozen streams, what viewers run into), fixed channel numbers, logos and guide ids from the
 * iptv-org database, the better copy of a channel, catch-up from the provider, and the device a play link was made for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->unsignedTinyInteger('reliability')->nullable()->after('quality_kbps');
            $table->unsignedSmallInteger('stream_speed')->nullable()->after('reliability');
            $table->unsignedBigInteger('hls_sequence')->nullable()->after('stream_speed');
            $table->unsignedInteger('number')->nullable()->after('sort_order')->index();
            $table->string('auto_logo_url', 2048)->nullable()->after('logo_url');
            $table->string('auto_tvg_id')->nullable()->after('tvg_id');
            $table->foreignId('better_copy_id')->nullable()->after('using_backup_id')->constrained('channels')->nullOnDelete();
            $table->string('catchup', 20)->nullable();
            $table->unsignedSmallInteger('catchup_days')->nullable();
            $table->string('catchup_source', 2048)->nullable();
        });

        // Checks and viewer trouble per channel and day: the last 7 days give the reliability score
        Schema::create('channel_check_days', function (Blueprint $table) {
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('slow')->default(0);
            $table->unsignedInteger('viewer_trouble')->default(0);
            $table->primary(['channel_id', 'day']);
            $table->index('day');
        });

        Schema::create('app_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('active_sessions', function (Blueprint $table) {
            $table->string('device_key', 32)->nullable()->after('user_agent')->index();
        });

        // Every channel gets a fixed number, in playlist order
        app(ChannelNumberService::class)->renumber();
    }

    public function down(): void
    {
        Schema::table('active_sessions', function (Blueprint $table) {
            $table->dropIndex(['device_key']);
            $table->dropColumn('device_key');
        });

        Schema::dropIfExists('app_settings');
        Schema::dropIfExists('channel_check_days');

        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['better_copy_id']);
            $table->dropIndex(['number']);
            $table->dropColumn(['reliability', 'stream_speed', 'hls_sequence', 'number', 'auto_logo_url', 'auto_tvg_id', 'better_copy_id', 'catchup', 'catchup_days', 'catchup_source']);
        });
    }
};
