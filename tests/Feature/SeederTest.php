<?php

use App\Models\User;
use Database\Seeders\SampleDataSeeder;

it('seeds the admin via the default seeder and is idempotent', function () {
    $this->seed();
    $this->seed();

    expect(User::where('username', 'admin')->where('role', 'admin')->count())->toBe(1);
});

it('seeds the sample data', function () {
    $this->seed(SampleDataSeeder::class);

    expect(User::where('role', 'user')->count())->toBe(3);
});
