<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('alert_on_down')->default(false)->after('auto_disabled');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->boolean('alert_on_down')->default(false)->after('auto_sync');
        });

        Schema::create('stream_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('mailed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_alerts');

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('alert_on_down');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('alert_on_down');
        });
    }
};
