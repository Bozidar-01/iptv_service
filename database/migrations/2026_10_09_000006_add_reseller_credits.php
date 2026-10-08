<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A reseller's credit balance (1 credit = 1 month of subscription for one user). null = no credit
            // system for this account: unlimited, as before.
            $table->integer('credits')->nullable();
        });

        // Every change of a balance, never edited or deleted (the account's history)
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();            // the reseller
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();    // who did it (admin / the reseller)
            $table->foreignId('customer_id')->nullable()->constrained('users')->nullOnDelete(); // the user it was spent on
            $table->integer('amount');                                                  // + given, - spent or taken
            $table->integer('balance_after')->nullable();                               // null: the account is unlimited
            $table->string('reason', 30);
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credits');
        });
    }
};
