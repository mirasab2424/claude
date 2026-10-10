<?php

namespace App\Providers;

use App\Support\PostDeploy;
use Filament\Resources\Resource;
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
        // В русском языке Заглавные Буквы В Каждом Слове выглядят странно.
        Resource::titleCaseModelLabel(false);

        if (! $this->app->runningInConsole()) {
            PostDeploy::runIfNeeded();
        }
    }
}
