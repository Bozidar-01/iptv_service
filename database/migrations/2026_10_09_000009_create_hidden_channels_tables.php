<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Channels hidden for one user. The bouquet is not changed: the user just doesn't get the channel.
        Schema::create('user_hidden_channels', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'channel_id']);
        });

        // Channels hidden for all users of one owner: a reseller, or (owner_id null) the admins' own users
        Schema::create('owner_hidden_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->unique(['owner_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_hidden_channels');
        Schema::dropIfExists('user_hidden_channels');
    }
};
