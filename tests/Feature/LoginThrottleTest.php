<?php

use App\Http\Controllers\Auth\AuthController;
use App\Models\User;
use App\Services\XtreamService;

it('locks admin sign-in after too many wrong passwords', function () {
    User::factory()->admin()->create(['username' => 'boss', 'password' => bcrypt('right-pass')]);

    for ($i = 0; $i < AuthController::MAX_ATTEMPTS; $i++) {
        $this->post('/login', ['username' => 'boss', 'password' => 'wrong']);
    }

    $this->post('/login', ['username' => 'boss', 'password' => 'right-pass'])
        ->assertSessionHasErrors('username');
    $this->assertGuest();
    expect(session('errors')->first('username'))->toContain('Too many sign-in attempts');

    $this->travel(61)->seconds();
    $this->post('/login', ['username' => 'boss', 'password' => 'right-pass'])->assertRedirect(route('admin.dashboard'));
});

it('blocks an IP that keeps guessing app passwords', function () {
    User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret')]);

    for ($i = 0; $i < XtreamService::MAX_FAILED_ATTEMPTS; $i++) {
        $this->get('/player_api.php?username=tv1&password=guess'.$i);
    }

    $this->get('/player_api.php?username=tv1&password=secret')->assertExactJson(['user_info' => ['auth' => 0]]);
    $this->get('/get.php?username=tv1&password=secret')->assertStatus(401);

    $this->travel(XtreamService::LOCKOUT_SECONDS + 1)->seconds();
    $this->get('/player_api.php?username=tv1&password=secret')->assertJsonPath('user_info.auth', 1);
});

it('does not count successful app logins', function () {
    User::factory()->create(['username' => 'tv1', 'password' => bcrypt('secret')]);

    for ($i = 0; $i < XtreamService::MAX_FAILED_ATTEMPTS + 5; $i++) {
        $this->get('/player_api.php?username=tv1&password=secret')->assertJsonPath('user_info.auth', 1);
    }
});
