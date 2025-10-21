<?php

namespace Greatplr\AmemberSso;

use Greatplr\AmemberSso\Http\Middleware\CheckAmemberProduct;
use Greatplr\AmemberSso\Http\Middleware\CheckAmemberSubscription;
use Greatplr\AmemberSso\Services\AmemberSsoService;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class AmemberSsoServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/amember-sso.php',
            'amember-sso'
        );

        $this->app->singleton(AmemberSsoService::class, function ($app) {
            return new AmemberSsoService(
                config('amember-sso.api_url'),
                config('amember-sso.api_key'),
                config('amember-sso.sso.secret_key')
            );
        });

        $this->app->alias(AmemberSsoService::class, 'amember-sso');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__.'/../config/amember-sso.php' => config_path('amember-sso.php'),
        ], 'amember-sso-config');

        // Publish migrations
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'amember-sso-migrations');

        // Load migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // Load routes
        if (config('amember-sso.webhook.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhook.php');
        }

        // Register middleware
        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('amember.product', CheckAmemberProduct::class);
        $router->aliasMiddleware('amember.subscription', CheckAmemberSubscription::class);

        // Register commands if running in console
        if ($this->app->runningInConsole()) {
            $this->commands([
                // Add console commands here if needed
            ]);
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            AmemberSsoService::class,
            'amember-sso',
        ];
    }
}
