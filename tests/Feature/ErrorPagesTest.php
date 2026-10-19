<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

it('shows the NeboTV page for an unknown address', function () {
    $this->get('/no-such-page')->assertNotFound()
        ->assertSee('Page not found')->assertSee('Go back')->assertSee('css/admin.css', false)
        // Signed out: nothing that leads into the admin panel
        ->assertDontSee('Go to the dashboard');
});

it('offers the dashboard to a signed-in admin', function () {
    Route::middleware('web')->get('/_test/missing', fn () => abort(404));

    $this->actingAs(User::factory()->admin()->create())->get('/_test/missing')->assertNotFound()
        ->assertSee('Go to the dashboard')->assertSee(route('admin.dashboard'), false);
});

it('shows a calm page instead of the error details when something breaks', function () {
    config(['app.debug' => false]);
    Route::get('/_test/broken', fn () => throw new RuntimeException('secret detail'));

    $this->get('/_test/broken')->assertStatus(500)
        ->assertSee('Something went wrong')->assertDontSee('secret detail');
});

it('has a page for each common error, in Macedonian too', function (int $code, string $title) {
    Route::get("/_test/{$code}", fn () => abort($code));

    $this->get("/_test/{$code}")->assertStatus($code)->assertSee($title);
    app()->setLocale('mk');
    expect(__($title))->not->toBe($title);
})->with([
    [403, 'No access'],
    [419, 'Page expired'],
    [429, 'Too many tries'],
    [503, 'Back soon'],
]);
