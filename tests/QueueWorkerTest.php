<?php

namespace Raccoon\Laravel\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Laravel\Nightwatch\Core;
use Psr\Http\Message\RequestInterface;
use Raccoon\Laravel\RaccoonServiceProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

class QueueWorkerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $records = [];

    protected function setUp(): void
    {
        RaccoonServiceProvider::$httpClient = fn () => new Client(['handler' => HandlerStack::create(function (RequestInterface $request) {
            if ($request->getUri()->getPath() === '/api/ingest') {
                array_push($this->records, ...json_decode(gzdecode((string) $request->getBody()), true)['records']);
            }

            return (new MockHandler([new Response(200, [], json_encode(['token' => 'short', 'refresh_in' => 3000, 'ingest_url' => 'https://raccoon.test/api/ingest']))]))($request, []);
        })]);

        parent::setUp();
    }

    protected function tearDown(): void
    {
        RaccoonServiceProvider::$httpClient = null;

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'database');
        $app['config']->set('queue.connections.database.connection', 'testing');
    }

    public function test_a_worker_with_no_agent_reports_its_job_attempts()
    {
        Schema::create('jobs', function ($table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        // Jobs follow the sampling of whatever dispatched them; a test has no sampled request.
        $this->app->make(Core::class)->sample(1.0);
        ReportJob::dispatch();
        // `php artisan queue:work` fires these, Artisan::call() doesn't. Nightwatch hooks into
        // jobs when the command starts and sends what's left when it finishes.
        event(new CommandStarting('queue:work', new ArrayInput([]), new NullOutput));
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        event(new CommandFinished('queue:work', new ArrayInput([]), new NullOutput, 0));

        $attempts = array_values(array_filter($this->records, fn ($record) => $record['t'] === 'job-attempt'));

        $this->assertCount(1, $attempts, 'Records sent: '.implode(', ', array_column($this->records, 't')));
        $this->assertSame(ReportJob::class, $attempts[0]['name']);
        $this->assertSame('processed', $attempts[0]['status']);
    }
}

class ReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        //
    }
}
