<?php

use App\Http\Controllers\Admin\AlertController;
use App\Http\Controllers\Admin\BouquetController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\SourceController;
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
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Panel language and light/dark mode (cookies), available before and after sign-in
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::post('/theme', [ThemeController::class, 'update'])->name('theme.update');

// Admin Routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('sources/{source}/sync', [SourceController::class, 'sync'])->name('sources.sync');
    Route::resource('sources', SourceController::class)->except(['show']);

    Route::post('channels/check-streams', [ChannelController::class, 'checkStreams'])->name('channels.check-streams');
    Route::get('channels/{channel}/preview', [ChannelController::class, 'preview'])->name('channels.preview');
    Route::post('channels/bulk', [ChannelController::class, 'bulk'])->name('channels.bulk');
    Route::resource('channels', ChannelController::class)->except(['show']);

    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('bouquets', BouquetController::class)->except(['show']);

    Route::post('users/{user}/toggle', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::post('users/{user}/playlist-token', [UserController::class, 'regeneratePlaylistToken'])->name('users.playlist-token');
    Route::resource('users', UserController::class);

    Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
    Route::post('alerts/read', [AlertController::class, 'markAllRead'])->name('alerts.read');
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
});

// Player endpoints (M3U, play, Xtream Codes API) are in routes/player.php
