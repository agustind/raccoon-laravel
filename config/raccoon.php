<?php

return [

    /*
     * The Raccoon deployment, the same URL the agent uses.
     */
    'base_url' => env('NIGHTWATCH_BASE_URL'),

    /*
     * How this process sends events:
     *
     * - "auto": through the agent when one listens on the Nightwatch ingest
     *   address, otherwise straight to Raccoon over HTTPS. Only console
     *   processes (queue workers, scheduled commands) are checked, so web
     *   requests always use the agent.
     * - "http": always straight to Raccoon. Use it for web requests that run
     *   without an agent, such as Vapor.
     * - "agent": never; the package does nothing.
     */
    'ingest' => env('RACCOON_INGEST', 'auto'),

    /*
     * Seconds to wait for Raccoon before dropping a batch.
     */
    'timeout' => (float) env('RACCOON_INGEST_TIMEOUT', 3),

];
