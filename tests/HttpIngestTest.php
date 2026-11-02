<?php

namespace Raccoon\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Laravel\Nightwatch\Core;
use Psr\Http\Message\RequestInterface;
use Raccoon\Laravel\HttpIngest;
use Raccoon\Laravel\RaccoonServiceProvider;
use RuntimeException;

class HttpIngestTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    /** @var array<string, list<Response>> responses queued per path; the last one repeats */
    private array $responses = [];

    protected function setUp(): void
    {
        $this->responses = [
            '/api/agent-auth' => [new Response(200, [], json_encode(['token' => 'short-1', 'expires_in' => 3600, 'refresh_in' => 3000, 'ingest_url' => 'https://raccoon.test/api/ingest']))],
            '/api/ingest' => [new Response(200, [], '{"message":"ok"}')],
        ];

        RaccoonServiceProvider::$httpClient = fn () => new Client(['handler' => HandlerStack::create(function (RequestInterface $request) {
            $this->sent[] = $request;
            $queue = &$this->responses[$request->getUri()->getPath()];
            $response = count($queue) > 1 ? array_shift($queue) : $queue[0];

            return (new MockHandler([$response]))($request, []);
        })]);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        RaccoonServiceProvider::$httpClient = null;

        parent::tearDown();
    }

    private function ingest(): HttpIngest
    {
        return $this->app->make(Core::class)->ingest;
    }

    /**
     * @return list<array{token: string, records: list<array<string, mixed>>}>
     */
    private function batches(): array
    {
        return array_values(array_map(fn (RequestInterface $request) => [
            'token' => $request->getHeaderLine('Authorization'),
            ...json_decode(gzdecode((string) $request->getBody()), true),
        ], array_filter($this->sent, fn ($request) => $request->getUri()->getPath() !== '/api/agent-auth')));
    }

    private function authCalls(): int
    {
        return count(array_filter($this->sent, fn ($request) => $request->getUri()->getPath() === '/api/agent-auth'));
    }

    public function test_a_digest_posts_the_records_gzipped_with_a_cached_ingest_token()
    {
        $this->ingest()->write(['t' => 'job-attempt', 'name' => 'App\Jobs\Report']);
        $this->ingest()->write(['t' => 'query', 'sql' => 'select 1']);
        $this->ingest()->digest();
        $this->ingest()->write(['t' => 'job-attempt', 'name' => 'App\Jobs\Report']);
        $this->ingest()->digest();
        $this->ingest()->digest();

        $this->assertSame(1, $this->authCalls());
        $this->assertSame('Bearer env-token', $this->sent[0]->getHeaderLine('Authorization'));
        $this->assertSame('gzip', $this->sent[1]->getHeaderLine('Content-Encoding'));
        $this->assertCount(2, $this->batches());
        $this->assertSame('Bearer short-1', $this->batches()[0]['token']);
        $this->assertSame(['job-attempt', 'query'], array_column($this->batches()[0]['records'], 't'));
        $this->assertSame(['job-attempt'], array_column($this->batches()[1]['records'], 't'), 'Its own cache and HTTP calls are not recorded.');
    }

    public function test_flushed_records_are_dropped()
    {
        $this->ingest()->write(['t' => 'query']);
        $this->ingest()->flush();
        $this->ingest()->digest();

        $this->assertSame([], $this->sent);
    }

    public function test_an_expired_token_authenticates_again_once()
    {
        $this->responses['/api/ingest'] = [new Response(401), new Response(200)];
        $this->responses['/api/agent-auth'][] = new Response(200, [], json_encode(['token' => 'short-2', 'refresh_in' => 3000, 'ingest_url' => 'https://raccoon.test/api/ingest']));

        $this->ingest()->writeNow(['t' => 'exception']);

        $this->assertSame(2, $this->authCalls());
        $this->assertSame(['Bearer short-1', 'Bearer short-2'], array_column($this->batches(), 'token'));
    }

    public function test_a_rejected_token_throws_for_nightwatchs_handler()
    {
        $this->responses['/api/agent-auth'] = [new Response(401, [], '{"message":"Unauthorized"}')];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Raccoon authentication failed [401]');

        $this->ingest()->writeNow(['t' => 'exception']);
    }
}
