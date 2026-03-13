<?php

namespace App\Providers;

use App\Models\Article;
use App\Observers\ArticleObserver;
use App\Services\VenteStockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VenteStockService::class);
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
        $this->configureDefaults();

        // Sync automatique articles → stocks lors de création/modification
        Article::observe(ArticleObserver::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        Password::defaults(
            fn(): ?Password => app()->isProduction()
                ? Password::min(12)->mixedCase()->letters()->numbers()->symbols()->uncompromised()
                : null,
        );
    }
}
