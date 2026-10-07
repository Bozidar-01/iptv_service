<?php

use App\Http\Controllers\Admin\BouquetController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\SourceController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\M3uController;
use App\Http\Controllers\PlayController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('login');
});

// Authentication Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Admin Routes
Route::middleware(['auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::post('sources/{source}/sync', [SourceController::class, 'sync'])->name('sources.sync');
    Route::resource('sources', SourceController::class)->except(['show']);

    Route::post('channels/bulk', [ChannelController::class, 'bulk'])->name('channels.bulk');
    Route::resource('channels', ChannelController::class)->except(['show']);

    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('bouquets', BouquetController::class)->except(['show']);

    Route::post('users/{user}/toggle', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::post('users/{user}/playlist-token', [UserController::class, 'regeneratePlaylistToken'])->name('users.playlist-token');
    Route::resource('users', UserController::class);

    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
});

// IPTV Public Endpoints
Route::get('/m3u', [M3uController::class, 'generate'])->name('m3u');
Route::get('/playlist/{token}.m3u', [M3uController::class, 'playlist'])->name('playlist');
Route::get('/play/{channel_id}/{token}', [PlayController::class, 'play'])->name('play');
