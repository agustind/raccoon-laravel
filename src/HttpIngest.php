<?php

namespace Raccoon\Laravel;

use Deprecated;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Request;
use Illuminate\Contracts\Cache\Store;
use Laravel\Nightwatch\Contracts\Ingest;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function count;
use function gzencode;
use function json_encode;

/**
 * Does the agent's job inside the process: keeps the records of one
 * execution (a job attempt, a command) and posts them to Raccoon when the
 * package digests them, which is once the execution ends.
 *
 * The short-lived ingest token from /api/agent-auth is cached, so a worker
 * authenticates about every few minutes, not once per job. A failed batch
 * throws, like the TCP ingest does, and Nightwatch's unrecoverable exception
 * handler gets it; the job itself is unaffected.
 *
 * It uses a plain Guzzle client and the raw cache store on purpose: Laravel's
 * HTTP client and cache repository report to Nightwatch's sensors, so every
 * batch would record its own POST and token lookup.
 */
class HttpIngest implements Ingest
{
    /** @var list<array<mixed>> */
    private array $records = [];

    private bool $shouldDigestWhenBufferIsFull = true;

    public function __construct(
        private ClientInterface $http,
        private Store $cache,
        private string $baseUrl,
        private string $token,
        private float $timeout = 3.0,
        private int $bufferLength = 500,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function write(array $record): void
    {
        $this->records[] = $record;

        if ($this->shouldDigestWhenBufferIsFull && count($this->records) >= $this->bufferLength) {
            $this->digest();
        }
    }

    public function writeNow(array $record): void
    {
        $this->send([$record]);
    }

    public function ping(): void
    {
        //
    }

    #[Deprecated('Use shouldDigestWhenBufferIsFull instead')]
    public function shouldDigest(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull($bool);
    }

    public function shouldDigestWhenBufferIsFull(bool $bool = true): void
    {
        $this->shouldDigestWhenBufferIsFull = $bool;
    }

    public function digest(): void
    {
        $records = $this->records;
        $this->records = [];

        if ($records !== []) {
            $this->send($records);
        }
    }

    public function flush(): void
    {
        $this->records = [];
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function send(array $records): void
    {
        // The agent's encoding, so records read the same on the server.
        $body = gzencode('{"records":'.json_encode($records, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE).'}');

        $response = $this->post($this->session(), $body);

        if ($response->getStatusCode() === 401) {
            // The cached token expired or was revoked: authenticate once more.
            $response = $this->post($this->session(fresh: true), $body);
        }

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException("Raccoon rejected {$this->count($records)} [{$response->getStatusCode()}]: ".substr((string) $response->getBody(), 0, 200));
        }
    }

    /**
     * @param  array{token: string, ingest_url: string}  $session
     */
    private function post(array $session, string $body): ResponseInterface
    {
        return $this->http->send(new Request('POST', $session['ingest_url'], [
            'Authorization' => "Bearer {$session['token']}",
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
            'User-Agent' => 'raccoon-laravel',
        ], $body), ['timeout' => $this->timeout, 'http_errors' => false]);
    }

    /**
     * @return array{token: string, ingest_url: string}
     */
    private function session(bool $fresh = false): array
    {
        $key = 'raccoon:ingest-session:'.substr(hash('sha256', $this->baseUrl.$this->token), 0, 16);

        if (! $fresh && is_array($session = $this->cache->get($key))) {
            return $session;
        }

        $response = $this->http->send(new Request('POST', "{$this->baseUrl}/api/agent-auth", [
            'Authorization' => "Bearer {$this->token}",
            'Accept' => 'application/json',
            'User-Agent' => 'raccoon-laravel',
        ]), ['timeout' => $this->timeout, 'http_errors' => false]);
        $json = json_decode((string) $response->getBody(), true);

        if ($response->getStatusCode() >= 400 || ! is_string($json['token'] ?? null)) {
            throw new RuntimeException("Raccoon authentication failed [{$response->getStatusCode()}]: ".substr((string) $response->getBody(), 0, 200));
        }

        $session = ['token' => $json['token'], 'ingest_url' => (string) (($json['ingest_url'] ?? null) ?: "{$this->baseUrl}/api/ingest")];
        // Refresh when the agent would, and well before the token expires.
        $ttl = max(30, (int) ($json['refresh_in'] ?? ((int) ($json['expires_in'] ?? 300) - 60)));
        $this->cache->put($key, $session, $ttl);

        return $session;
    }

    /**
     * @param  list<array<mixed>>  $records
     */
    private function count(array $records): string
    {
        return count($records).' '.(count($records) === 1 ? 'record' : 'records');
    }
}
