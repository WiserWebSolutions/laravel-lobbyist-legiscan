<?php

namespace WiserWebSolutions\Lobbyist\Legiscan\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use WiserWebSolutions\Lobbyist\Legiscan\LegiscanServiceProvider;
use WiserWebSolutions\Lobbyist\LobbyistServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LobbyistServiceProvider::class,
            LegiscanServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('lobbyist-legiscan.endpoint.api_key', 'test-key');
        $app['config']->set('lobbyist-legiscan.endpoint.base_uri', 'https://api.legiscan.test/');
        $app['config']->set('lobbyist-legiscan.request', [
            'timeout' => 5,
            'retry_times' => 1,
            'retry_sleep_ms' => 0,
        ]);
        // Caching off by default; individual tests opt in.
        $app['config']->set('lobbyist-legiscan.cache.enabled', false);
        $app['config']->set('lobbyist-legiscan.cache.store', 'array');
        $app['config']->set('lobbyist-legiscan.cache.ttl', 3600);
    }

    /**
     * Fake the LegiScan endpoint, dispatching by the `op` query parameter.
     *
     * @param  array<string, array>  $responsesByOp
     */
    protected function fakeLegiscan(array $responsesByOp): void
    {
        Http::fake([
            'api.legiscan.test/*' => function (Request $request) use ($responsesByOp) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $op = $query['op'] ?? '';

                $payload = $responsesByOp[$op] ?? ['status' => 'ERROR', 'alert' => ['message' => "No fake for op [{$op}]"]];

                return Http::response($payload);
            },
        ]);
    }

    protected function okResponse(array $data): array
    {
        return ['status' => 'OK'] + $data;
    }
}
