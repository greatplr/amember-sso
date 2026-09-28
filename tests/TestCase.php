<?php

namespace Greatplr\AmemberSso\Tests;

use Greatplr\AmemberSso\AmemberSsoServiceProvider;
use Greatplr\AmemberSso\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [AmemberSsoServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('amember-sso.user_model', User::class);
    }

    /**
     * The package's own migrations are registered by the service provider
     * (loadMigrationsFrom), so only the host-app `users` table is added here.
     * Its filename sorts before the package migrations that alter it.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
    }
}
