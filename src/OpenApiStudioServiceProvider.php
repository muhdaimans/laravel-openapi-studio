<?php

namespace OpenApiStudio;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OpenApiStudio\Http\Middleware\EnsureEditorIsEnabled;
use OpenApiStudio\Support\SpecSerializer;
use OpenApiStudio\Support\SpecValidator;

class OpenApiStudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/openapi-studio.php', 'openapi-studio');

        $this->app->bind(SpecRepository::class, fn ($app) => new SpecRepository(
            $app->make(Filesystem::class),
            $app->make(SpecSerializer::class),
            $app->make(SpecValidator::class),
            (string) $app['config']->get('openapi-studio.directory'),
            (int) $app['config']->get('openapi-studio.backups', 10),
        ));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'openapi-studio');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/openapi-studio.php' => config_path('openapi-studio.php'),
            ], 'openapi-studio-config');
        }

        Route::group([
            'prefix' => config('openapi-studio.route.prefix', 'openapi-studio'),
            // The environment gate runs first, so staging/production always get a 404.
            'middleware' => [EnsureEditorIsEnabled::class, ...(array) config('openapi-studio.route.middleware', ['web'])],
            'as' => 'openapi-studio.',
        ], fn () => $this->loadRoutesFrom(__DIR__.'/../routes/web.php'));
    }
}
