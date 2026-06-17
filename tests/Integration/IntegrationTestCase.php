<?php

namespace Siberfx\Typesense\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Typesense;
use Typesense\Client;

/**
 * Base case for integration tests that talk to a real Typesense server.
 *
 * Connection details are read from TYPESENSE_* environment variables (falling
 * back to localhost:8108 / api key "xyz"). When no healthy server is reachable
 * every test is skipped, so the suite is safe to run anywhere — CI with a live
 * Typesense will execute them, local runs without one will skip cleanly.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected Client $client;

    protected Typesense $typesense;

    /**
     * Cached skip reason once we have determined the server is unreachable, so
     * we don't pay the connection timeout on every test in the suite.
     */
    private static ?string $unavailableReason = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$unavailableReason !== null) {
            $this->markTestSkipped(self::$unavailableReason);
        }

        $config = [
            'api_key' => getenv('TYPESENSE_API_KEY') ?: 'xyz',
            'nodes' => [
                [
                    'host' => getenv('TYPESENSE_HOST') ?: 'localhost',
                    'port' => getenv('TYPESENSE_PORT') ?: '8108',
                    'protocol' => getenv('TYPESENSE_PROTOCOL') ?: 'http',
                ],
            ],
            // Fail fast when there is no server, so skips are quick.
            'connection_timeout_seconds' => 1,
            'num_retries' => 1,
            'retry_interval_seconds' => 1,
        ];

        try {
            $client = new Client($config);
            $health = $client->getHealth()->retrieve();
        } catch (\Throwable $e) {
            self::$unavailableReason = 'Typesense server not available: ' . $e->getMessage();
            $this->markTestSkipped(self::$unavailableReason);
        }

        if (empty($health['ok'])) {
            self::$unavailableReason = 'Typesense server reported unhealthy.';
            $this->markTestSkipped(self::$unavailableReason);
        }

        $this->client = $client;
        $this->typesense = new Typesense($client);
    }

    /**
     * Delete a collection, ignoring "not found" errors.
     */
    protected function dropCollection(string $name): void
    {
        try {
            $this->client->getCollections()[$name]->delete();
        } catch (\Throwable $e) {
            // Collection did not exist — nothing to clean up.
        }
    }
}
