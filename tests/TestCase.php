<?php

namespace Raccoon\Laravel\Tests;

use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Raccoon\Laravel\RaccoonServiceProvider;

abstract class TestCase extends Orchestra
{
    /** Whether the fake agent port answers. */
    protected bool $agentListening = false;

    protected function setUp(): void
    {
        RaccoonServiceProvider::$agentProbe = fn () => $this->agentListening;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        RaccoonServiceProvider::$agentProbe = null;

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [NightwatchServiceProvider::class, RaccoonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('nightwatch.token', 'env-token');
        $app['config']->set('raccoon.base_url', 'https://raccoon.test');
    }
}
