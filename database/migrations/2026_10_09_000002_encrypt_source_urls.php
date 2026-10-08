<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Provider playlist addresses contain the provider login, so sources.url is stored encrypted
 * (Source casts it as `encrypted`). An encrypted value is much longer than the address: text column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->text('url')->nullable()->change();
        });

        DB::table('sources')->whereNotNull('url')->orderBy('id')->each(function ($row) {
            DB::table('sources')->where('id', $row->id)->update(['url' => $this->isEncrypted($row->url) ? $row->url : Crypt::encryptString($row->url)]);
        });
    }

    public function down(): void
    {
        DB::table('sources')->whereNotNull('url')->orderBy('id')->each(function ($row) {
            DB::table('sources')->where('id', $row->id)->update(['url' => $this->isEncrypted($row->url) ? Crypt::decryptString($row->url) : $row->url]);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->change();
        });
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
};
