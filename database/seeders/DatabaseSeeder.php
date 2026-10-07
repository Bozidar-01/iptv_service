<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Demo data is opt-in: php artisan db:seed --class=SampleDataSeeder
     */
    public function run(): void
    {
        $this->call(AdminSeeder::class);
    }
}
