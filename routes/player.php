<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\M3uController;
use App\Http\Controllers\PlayController;
use App\Http\Controllers\XtreamController;
use Illuminate\Support\Facades\Route;

/*
| Endpoints used by TV IPTV apps. Loaded without the "web" middleware group (see bootstrap/app.php),
| so they create no sessions or cookies and need no CSRF token.
*/

// For an uptime monitor: 503 when the scheduler or queue has stopped
Route::get('/health', HealthController::class)->name('health');

// M3U playlist link and username/password playlist
Route::get('/m3u', [M3uController::class, 'generate'])->name('m3u');
Route::get('/playlist/{token}.m3u', [M3uController::class, 'playlist'])->name('playlist');
Route::get('/play/{channel_id}/{token}', [PlayController::class, 'play'])->name('play');
Route::get('/epg/{token}.xml', [M3uController::class, 'epg'])->name('epg');

// Xtream Codes API: apps log in with server URL + username + password
Route::match(['get', 'post'], '/player_api.php', [XtreamController::class, 'api'])->name('xtream.api');
Route::match(['get', 'post'], '/get.php', [M3uController::class, 'generate'])->name('xtream.playlist');
Route::get('/xmltv.php', [XtreamController::class, 'xmltv'])->name('xtream.xmltv');
Route::get('/live/{username}/{password}/{stream}', [XtreamController::class, 'live'])->name('xtream.live');
