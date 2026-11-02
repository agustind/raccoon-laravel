<?php

namespace Raccoon\Laravel\Tests;

use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\Ingest as AgentIngest;
use Raccoon\Laravel\HttpIngest;

class ServiceProviderTest extends TestCase
{
    public function test_console_processes_without_an_agent_send_over_https()
    {
        $this->assertInstanceOf(HttpIngest::class, $this->app->make(Core::class)->ingest);
    }

    public function test_an_agent_on_the_ingest_address_is_kept()
    {
        $this->agentListening = true;
        $this->refreshApplication();

        $this->assertInstanceOf(AgentIngest::class, $this->app->make(Core::class)->ingest);
    }

    public function test_http_mode_skips_the_check_and_agent_mode_turns_the_package_off()
    {
        $this->agentListening = true;
        $this->refreshWithConfig(['raccoon.ingest' => 'http']);
        $this->assertInstanceOf(HttpIngest::class, $this->app->make(Core::class)->ingest);

        $this->agentListening = false;
        $this->refreshWithConfig(['raccoon.ingest' => 'agent']);
        $this->assertInstanceOf(AgentIngest::class, $this->app->make(Core::class)->ingest);
    }

    public function test_nothing_changes_without_a_base_url()
    {
        $this->refreshWithConfig(['raccoon.base_url' => null]);

        $this->assertInstanceOf(AgentIngest::class, $this->app->make(Core::class)->ingest);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function refreshWithConfig(array $config): void
    {
        $this->extraConfig = $config;
        $this->refreshApplication();
    }

    /** @var array<string, mixed> */
    private array $extraConfig = [];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        foreach ($this->extraConfig as $key => $value) {
            $app['config']->set($key, $value);
        }
    }
}
