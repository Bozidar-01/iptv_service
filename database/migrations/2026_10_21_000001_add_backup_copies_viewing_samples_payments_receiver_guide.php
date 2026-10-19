<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backup copies (a duplicate kept only as a backup), streams at once over time, prices / sales / reseller payments, and a TV guide
 * read from a satellite receiver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('backup_for_id')->nullable()->after('better_copy_id')->constrained('channels')->nullOnDelete();
        });

        Schema::create('viewing_samples', function (Blueprint $table) {
            $table->id();
            $table->dateTime('taken_at')->index();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedInteger('streams');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('price_per_month', 10, 2)->nullable()->after('credits');
            $table->decimal('credit_price', 10, 2)->nullable()->after('price_per_month');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->nullable()->after('balance_after');
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            // The seller: a reseller, or null for the admins' own users
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name');
            $table->string('kind', 10);
            $table->unsignedSmallInteger('months');
            $table->decimal('amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['owner_id', 'paid_at']);
            $table->index('created_at');
        });

        Schema::create('reseller_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reseller_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('note')->nullable();
            $table->date('paid_on');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('epg_sources', function (Blueprint $table) {
            $table->foreignId('receiver_source_id')->nullable()->after('url')->constrained('sources')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('epg_sources', function (Blueprint $table) {
            $table->dropForeign(['receiver_source_id']);
            $table->dropColumn('receiver_source_id');
        });
        Schema::dropIfExists('reseller_payments');
        Schema::dropIfExists('sales');
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropColumn('price');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['price_per_month', 'credit_price']);
        });
        Schema::dropIfExists('viewing_samples');
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['backup_for_id']);
            $table->dropColumn('backup_for_id');
        });
    }
};
