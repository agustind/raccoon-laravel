<?php

namespace Raccoon\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\ServiceProvider;
use Laravel\Nightwatch\Core;
use Throwable;

/**
 * Replaces Nightwatch's agent transport with HttpIngest in processes that
 * have no agent to talk to. Everything else (sensors, sampling, records)
 * stays the package's own.
 */
class RaccoonServiceProvider extends ServiceProvider
{
    /** @var (callable(string): bool)|null */
    public static $agentProbe = null;

    /** @var (callable(): ClientInterface)|null */
    public static $httpClient = null;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/raccoon.php', 'raccoon');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/raccoon.php' => config_path('raccoon.php')], 'raccoon-config');
        }

        try {
            if ($this->shouldSendOverHttp()) {
                $this->app->make(Core::class)->ingest = new HttpIngest(
                    http: static::$httpClient ? (static::$httpClient)() : new Client,
                    cache: $this->app->make('cache')->store()->getStore(),
                    baseUrl: (string) config('raccoon.base_url'),
                    token: (string) config('nightwatch.token'),
                    timeout: (float) config('raccoon.timeout', 3),
                    bufferLength: (int) config('nightwatch.ingest.event_buffer', 500),
                );
            }
        } catch (Throwable $e) {
            // Monitoring must never stop the app from booting.
            report($e);
        }
    }

    private function shouldSendOverHttp(): bool
    {
        $mode = config('raccoon.ingest', 'auto');

        if ($mode === 'agent' || ! $this->app->bound(Core::class)) {
            return false;
        }

        if (! config('nightwatch.enabled', true) || ! config('nightwatch.token') || ! config('raccoon.base_url')) {
            return false;
        }

        if ($mode === 'http') {
            return true;
        }

        return $this->app->runningInConsole() && ! $this->agentListening((string) config('nightwatch.ingest.uri', '127.0.0.1:2407'));
    }

    private function agentListening(string $address): bool
    {
        if (static::$agentProbe) {
            return (static::$agentProbe)($address);
        }

        $socket = @stream_socket_client("tcp://{$address}", $code, $message, 0.05);

        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }
}
