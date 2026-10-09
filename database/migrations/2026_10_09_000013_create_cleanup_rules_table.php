<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rules that tidy names and categories on every import (CleanupRuleService)
        Schema::create('cleanup_rules', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);          // channel_name | category | channel_category
            $table->string('match', 10);         // contains | equals | regex
            $table->string('pattern');
            $table->string('replacement')->nullable();
            $table->string('when_category')->nullable(); // channel_category only: apply to channels that are in this category
            $table->foreignId('source_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cleanup_rules');
    }
};
