<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The launch checklist's manual items an admin has ticked off (LaunchChecklistService)
        Schema::create('checklist_ticks', function (Blueprint $table) {
            $table->id();
            $table->string('item', 50)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_ticks');
    }
};
