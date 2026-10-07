<?php

namespace App\Providers;

use App\Models\StreamAlert;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Header bell (admin.partials.notifications)
        View::composer('layouts.admin', function ($view) {
            $view->with('alertMenu', Auth::check() ? [
                'unread' => StreamAlert::unread()->count(),
                'latest' => StreamAlert::latest('id')->limit(8)->get(),
            ] : ['unread' => 0, 'latest' => collect()]);
        });
    }
}
