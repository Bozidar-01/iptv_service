<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A third role, "reseller": the enum becomes a plain string so roles can be added without altering it again
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('user')->change();
        });

        Schema::table('users', function (Blueprint $table) {
            // Whose customer this subscriber is (a reseller). Deleting the reseller keeps the subscriber.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // Reseller limits: how many customers, and the most simultaneous streams a customer may get (null = no limit)
            $table->unsignedInteger('max_customers')->nullable();
            $table->unsignedSmallInteger('max_customer_connections')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // FK first, then the column (MySQL keeps an index for the FK until it is dropped)
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn(['max_customers', 'max_customer_connections']);
        });

        // Resellers cannot exist in the old enum: they become plain subscribers
        DB::table('users')->where('role', 'reseller')->update(['role' => 'user']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'user'])->default('user')->change();
        });
    }
};
