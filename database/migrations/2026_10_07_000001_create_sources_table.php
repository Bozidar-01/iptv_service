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
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['url', 'file'])->default('url');
            $table->string('url', 2048)->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('bouquet_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('skip_vod')->default(true);
            $table->boolean('auto_sync')->default(true);
            $table->string('status')->default('pending');
            $table->text('last_error')->nullable();
            $table->unsignedInteger('channel_count')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
