<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\User::firstOrCreate(['username' => 'nebojsa'], [
            'password' => \Illuminate\Support\Facades\Hash::make('Pejoski3009!'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
