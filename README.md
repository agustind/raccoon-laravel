# raccoonwatch/laravel

Sends Laravel Nightwatch events to [Raccoon](https://raccoon.watch) from processes that have no agent next to them, such as Laravel Cloud's managed queue, Vapor, or worker servers where you can't run `php artisan nightwatch:agent`.

Nightwatch's package hands events to an agent on `127.0.0.1:2407`, and the agent sends them on. Where nothing listens on that port, the events are dropped. With this package installed, a queue worker or scheduled command that finds no agent posts its events to Raccoon over HTTPS at the end of each job or command. Everything else stays as it is: the Nightwatch package still records the events, and processes that have an agent keep using it.

## Install

```bash
composer require raccoonwatch/laravel
```

It reads the variables you already set for the agent:

```bash
NIGHTWATCH_TOKEN=<token from Settings → Environments>
NIGHTWATCH_BASE_URL=https://<your Raccoon deployment>
```

Deploy, and job attempts from the workers show up on the Jobs page.

## How it decides

`RACCOON_INGEST` picks the transport:

| Value | Behavior |
| --- | --- |
| `auto` (default) | Console processes (queue workers, scheduled commands) check for an agent on `NIGHTWATCH_INGEST_URI` (default `127.0.0.1:2407`) when they boot. No agent: HTTPS. Web requests always use the agent. |
| `http` | Always HTTPS, web requests included. Use it where web requests run without an agent, such as Vapor. |
| `agent` | The package does nothing. |

Each worker authenticates with `NIGHTWATCH_TOKEN` and caches the short-lived ingest token in your default cache store until the agent would refresh it. A job's events go out in one gzipped POST after the job finishes, so the job's own duration doesn't include it. If Raccoon can't be reached within `RACCOON_INGEST_TIMEOUT` seconds (default 3), that batch is dropped and Nightwatch's error handler gets the exception; the job isn't affected.

To change these in a config file, publish it with `php artisan vendor:publish --tag=raccoon-config`.

## Requirements

PHP 8.2+, Laravel 11 to 13, and `laravel/nightwatch` 1.30 or later. The package replaces the transport through Nightwatch's `Contracts\Ingest` interface, which Nightwatch marks internal; check job events after upgrading `laravel/nightwatch`.

## Tests

```bash
composer install
vendor/bin/phpunit
```
