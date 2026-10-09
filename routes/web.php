<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\BouquetController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\CreditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EpgSourceController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ThemeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::get('/two-factor', [AuthController::class, 'showTwoFactor'])->name('two-factor.show');
Route::post('/two-factor', [AuthController::class, 'twoFactor'])->name('two-factor.verify');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Panel language and light/dark mode (cookies), available before and after sign-in
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::post('/theme', [ThemeController::class, 'update'])->name('theme.update');

// Panel for admins and resellers. Resellers only ever reach their own customers ({user} is scoped, see
// AppServiceProvider and User::scopeVisibleTo)
Route::middleware(['auth', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('users/{user}/toggle', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::post('users/{user}/playlist-token', [UserController::class, 'regeneratePlaylistToken'])->name('users.playlist-token');
    Route::post('users/{user}/sessions/disconnect', [UserController::class, 'disconnectAllDevices'])->name('users.sessions.disconnect-all');
    Route::post('users/{user}/sessions/{session}/disconnect', [UserController::class, 'disconnectDevice'])->name('users.sessions.disconnect');
    Route::resource('users', UserController::class);

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/read', [AlertController::class, 'markAllRead'])->name('alerts.read');
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
    Route::get('credits', [CreditController::class, 'index'])->name('credits.index');

    Route::get('security', [SecurityController::class, 'index'])->name('security.index');
    Route::post('security/two-factor', [SecurityController::class, 'start'])->name('security.start');
    Route::post('security/two-factor/confirm', [SecurityController::class, 'confirm'])->name('security.confirm');
    Route::post('security/two-factor/recovery-codes', [SecurityController::class, 'recoveryCodes'])->name('security.recovery-codes');
    Route::post('security/two-factor/disable', [SecurityController::class, 'disable'])->name('security.disable');
});

// Admin-only: content, sources, TV guide, statistics
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('sources/{source}/sync', [SourceController::class, 'sync'])->name('sources.sync');
    Route::resource('sources', SourceController::class)->except(['show']);

    Route::post('channels/check-streams', [ChannelController::class, 'checkStreams'])->name('channels.check-streams');
    Route::get('channels/{channel}/preview', [ChannelController::class, 'preview'])->name('channels.preview');
    Route::post('channels/bulk', [ChannelController::class, 'bulk'])->name('channels.bulk');
    Route::resource('channels', ChannelController::class)->except(['show']);

    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('bouquets', BouquetController::class)->except(['show']);

    Route::get('epg', [EpgSourceController::class, 'index'])->name('epg.index');
    Route::post('epg', [EpgSourceController::class, 'store'])->name('epg.store');
    Route::post('epg/match', [EpgSourceController::class, 'match'])->name('epg.match');
    Route::post('epg/{epgSource}/sync', [EpgSourceController::class, 'sync'])->name('epg.sync');
    Route::delete('epg/{epgSource}', [EpgSourceController::class, 'destroy'])->name('epg.destroy');
    Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');

    // Giving and taking back credits is for admins only
    Route::post('users/{user}/credits', [CreditController::class, 'store'])->name('users.credits.store');
    Route::post('users/{user}/credits/unlimited', [CreditController::class, 'unlimited'])->name('users.credits.unlimited');
});

// Player endpoints (M3U, play, Xtream Codes API) are in routes/player.php
