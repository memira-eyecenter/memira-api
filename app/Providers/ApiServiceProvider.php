<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\GoogleService;
use App\Services\SalesforceService;

class ApiServiceProvider extends ServiceProvider {
    /**
     * Register services.
     *
     * @return void
     */
    public function register() {
        $this->app->singleton(GoogleService::class, function ($app) {
            return new GoogleService();
        });

        $this->app->singleton(SalesforceService::class, function ($app) {
            return new SalesforceService();
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot() {
        //
    }
}
