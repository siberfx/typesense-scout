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

    public function test_the_template_escapes_where_values_by_default(): void
    {
        $typesense = $this->config()['typesense'];

        $this->assertArrayHasKey('escape_filter_values', $typesense);
        $this->assertTrue(
            $typesense['escape_filter_values'],
            'New installs must escape where() values so request input cannot change the filter.'
        );
    }

    public function test_the_template_does_not_default_the_api_key_to_a_placeholder(): void
    {
        // A placeholder key would be sent to the server and fail with a 401 later.
        // Reading the source keeps the test independent of TYPESENSE_API_KEY in the environment.
        $source = file_get_contents(__DIR__ . '/../../config/scout.php');

        $this->assertStringNotContainsString(
            "'xyz'",
            $source,
            'The API key must come from TYPESENSE_API_KEY without a placeholder default.'
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
