<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\ChannelController;
use App\Http\Controllers\Admin\BouquetController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\LogController;
use App\Http\Controllers\Admin\DashboardController;

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

    // CRUDs
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('channels', ChannelController::class)->except(['show']);
    Route::resource('bouquets', BouquetController::class)->except(['show']);
    Route::resource('users', UserController::class)->except(['show']);
    Route::post('users/{user}/toggle', [UserController::class, 'toggleStatus'])->name('users.toggle');
    Route::get('logs', [LogController::class, 'index'])->name('logs.index');
});

// IPTV Public Endpoints
Route::get('/m3u', [App\Http\Controllers\M3uController::class, 'generate'])->name('m3u');
Route::get('/play/{channel_id}/{token}', [App\Http\Controllers\PlayController::class, 'play'])->name('play');
