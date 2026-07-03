<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Standalone Connection
    |--------------------------------------------------------------------------
    |
    | The connection used when you call the standalone client without naming
    | one. Standalone Typesense access is independent of Laravel Scout.
    |
    */

    'default' => env('TYPESENSE_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Each connection is passed to the Typesense PHP client. Any value left
    | null (and an empty `nodes`/`nearest_node`) on the `default` connection
    | falls back to `scout.typesense.client-settings`, so existing apps need
    | no new configuration. Named connections must be fully specified.
    |
    */

    'connections' => [

        'default' => [
            'api_key' => env('TYPESENSE_API_KEY'),
            'nodes' => array_values(array_filter([
                [
                    'host'     => env('TYPESENSE_HOST'),
                    'port'     => env('TYPESENSE_PORT'),
                    'path'     => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL'),
                ],
            ], static fn (array $node): bool => ! empty($node['host']))),
            'nearest_node' => null,
            // Left null by default so an unset env genuinely inherits the value
            // from scout.typesense.client-settings; set the env var to override.
            'connection_timeout_seconds'   => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS'),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS'),
            'num_retries'                  => env('TYPESENSE_NUM_RETRIES'),
            'retry_interval_seconds'       => env('TYPESENSE_RETRY_INTERVAL_SECONDS'),
        ],

    ],
];
