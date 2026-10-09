<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who changed what in the panel (AuditService). Names are kept as text so the row stays readable after a delete.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('action', 40)->index();
            $table->string('subject_type', 20)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable()->index();
            $table->string('subject_name')->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
