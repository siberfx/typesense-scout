<?php

namespace Siberfx\Typesense\Standalone;

use Typesense\Client;

/**
 * Builds a raw Typesense client from a connection settings array, applying an
 * optional fallback (e.g. the Scout client settings) for the default connection.
 */
class TypesenseConnectionFactory
{
    /**
     * Merge a connection's settings over a fallback array. A connection value
     * is used when present and meaningful; otherwise the fallback value applies.
     *
     * @param array $connection The connection-specific settings.
     * @param array $fallback   Values to inherit when the connection omits them.
     */
    public function resolveSettings(array $connection, array $fallback = []): array
    {
        $settings = $fallback;

        foreach ($connection as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (($key === 'nodes' || $key === 'nearest_node') && empty($value)) {
                continue;
            }

            $settings[$key] = $value;
        }

        return $settings;
    }

    /**
     * Construct a Typesense client. Construction does not open a connection;
     * the first request triggers HTTP client discovery (php-http/discovery).
     */
    public function make(array $settings): Client
    {
        return new Client($settings);
    }
}
