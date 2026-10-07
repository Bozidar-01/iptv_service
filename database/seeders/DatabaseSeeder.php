<?php

namespace Database\Seeders;

use App\Models\Source;
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

        // Only on a fresh install, so sources an admin deleted don't come back on every deploy
        if (!Source::exists()) {
            $this->call(DefaultSourcesSeeder::class);
        }
    }
}
