<?php

namespace WiserWebSolutions\Lobbyist\Legiscan;

use Illuminate\Support\ServiceProvider;
use WiserWebSolutions\Lobbyist\LobbyistManager;

class LegiscanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/lobbyist-legiscan.php', 'lobbyist-legiscan');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/lobbyist-legiscan.php' => config_path('lobbyist-legiscan.php'),
            ], 'lobbyist-legiscan-config');
        }

        // Register with the core manager lazily and regardless of provider
        // boot order. The driver is only constructed when actually resolved,
        // so a missing API key never breaks application bootstrap.
        $this->app->resolving('lobbyist', function (LobbyistManager $manager) {
            $manager->extend('legiscan', fn () => new LegiscanDriver(
                config('lobbyist-legiscan')
            ));
        });
    }
}
