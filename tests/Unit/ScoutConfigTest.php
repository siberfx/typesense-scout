<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards the published config shape against the keys the package code reads.
 * The service provider and Typesense class read
 * `scout.typesense.client-settings`, so that key must exist.
 */
class ScoutConfigTest extends TestCase
{
    private function config(): array
    {
        return require __DIR__ . '/../../config/scout.php';
    }

    public function test_typesense_client_settings_key_exists(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey('typesense', $config);
        $this->assertArrayHasKey(
            'client-settings',
            $config['typesense'],
            'The code reads scout.typesense.client-settings; the config must define it.'
        );
    }

    public function test_client_settings_contain_connection_details(): void
    {
        $clientSettings = $this->config()['typesense']['client-settings'];

        $this->assertArrayHasKey('api_key', $clientSettings);
        $this->assertArrayHasKey('nodes', $clientSettings);
        $this->assertNotEmpty($clientSettings['nodes']);
        $this->assertArrayHasKey('host', $clientSettings['nodes'][0]);
    }
}
