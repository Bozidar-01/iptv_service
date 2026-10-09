<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BouquetController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\CleanupRuleController;
use App\Http\Controllers\Admin\CreditController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EpgSourceController;
use App\Http\Controllers\Admin\HiddenChannelController;
use App\Http\Controllers\Admin\LaunchChecklistController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\Admin\TrialController;
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
    Route::post('users/bulk', [UserController::class, 'bulk'])->name('users.bulk');
    Route::post('users/{user}/renew', [UserController::class, 'renew'])->name('users.renew');
    Route::post('users/{user}/playlist-token', [UserController::class, 'regeneratePlaylistToken'])->name('users.playlist-token');
    Route::post('users/{user}/sessions/disconnect', [UserController::class, 'disconnectAllDevices'])->name('users.sessions.disconnect-all');
    Route::post('users/{user}/sessions/{session}/disconnect', [UserController::class, 'disconnectDevice'])->name('users.sessions.disconnect');
    Route::get('users/trial', [TrialController::class, 'create'])->name('users.trial');
    Route::post('users/trial', [TrialController::class, 'store'])->name('users.trial.store');
    Route::get('users/{user}/channels', [HiddenChannelController::class, 'user'])->name('users.channels');
    Route::post('users/{user}/channels', [HiddenChannelController::class, 'userBulk'])->name('users.channels.bulk');
    Route::resource('users', UserController::class);
    Route::get('hide-channels', [HiddenChannelController::class, 'index'])->name('hide-channels.index');
    Route::post('hide-channels', [HiddenChannelController::class, 'bulk'])->name('hide-channels.bulk');

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
    Route::get('cleanup', [CleanupRuleController::class, 'index'])->name('cleanup.index');
    Route::post('cleanup', [CleanupRuleController::class, 'store'])->name('cleanup.store');
    Route::post('cleanup/apply', [CleanupRuleController::class, 'apply'])->name('cleanup.apply');
    Route::post('cleanup/pack/{pack}', [CleanupRuleController::class, 'pack'])->name('cleanup.pack');
    Route::post('cleanup/{rule}/toggle', [CleanupRuleController::class, 'toggle'])->name('cleanup.toggle');
    Route::delete('cleanup/{rule}', [CleanupRuleController::class, 'destroy'])->name('cleanup.destroy');
    Route::get('channels/duplicates', [ChannelController::class, 'duplicates'])->name('channels.duplicates');
    Route::post('channels/duplicates', [ChannelController::class, 'applyDuplicates'])->name('channels.duplicates.apply');
    Route::post('channels/bulk', [ChannelController::class, 'bulk'])->name('channels.bulk');
    Route::resource('channels', ChannelController::class)->except(['show']);

    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('bouquets', BouquetController::class)->except(['show']);

    Route::get('epg', [EpgSourceController::class, 'index'])->name('epg.index');
    Route::post('epg', [EpgSourceController::class, 'store'])->name('epg.store');
    Route::post('epg/countries', [EpgSourceController::class, 'addCountries'])->name('epg.countries');
    Route::post('epg/match', [EpgSourceController::class, 'match'])->name('epg.match');
    Route::post('epg/{epgSource}/sync', [EpgSourceController::class, 'sync'])->name('epg.sync');
    Route::delete('epg/{epgSource}', [EpgSourceController::class, 'destroy'])->name('epg.destroy');
    Route::get('statistics', [StatisticsController::class, 'index'])->name('statistics.index');
    Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('launch-checklist', [LaunchChecklistController::class, 'index'])->name('launch-checklist.index');
    Route::post('launch-checklist/{item}', [LaunchChecklistController::class, 'toggle'])->name('launch-checklist.toggle');

    // Giving and taking back credits is for admins only
    Route::post('users/{user}/credits', [CreditController::class, 'store'])->name('users.credits.store');
    Route::post('users/{user}/credits/unlimited', [CreditController::class, 'unlimited'])->name('users.credits.unlimited');
});

// Player endpoints (M3U, play, Xtream Codes API) are in routes/player.php
