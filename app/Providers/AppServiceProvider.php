<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use SleekDB\Store;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Store::class, function () {
            return new Store('database', \Phar::running() ?
                $_SERVER['HOME'] . '/.hcpc/db/' :
                storage_path('app'));
        });
    }
}
