<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\TypesenseConnectionFactory;
use Typesense\Client;

class TypesenseConnectionFactoryTest extends TestCase
{
    private TypesenseConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->factory = new TypesenseConnectionFactory();
    }

    public function test_connection_value_overrides_fallback(): void
    {
        $settings = $this->factory->resolveSettings(
            ['api_key' => 'conn-key'],
            ['api_key' => 'scout-key', 'num_retries' => 3]
        );

        $this->assertSame('conn-key', $settings['api_key']);
        $this->assertSame(3, $settings['num_retries']); // fallback preserved
    }

    public function test_null_connection_value_falls_back(): void
    {
        $settings = $this->factory->resolveSettings(
            ['api_key' => null],
            ['api_key' => 'scout-key']
        );

        $this->assertSame('scout-key', $settings['api_key']);
    }

    public function test_empty_nodes_falls_back(): void
    {
        $fallbackNodes = [['host' => 'scout-host', 'port' => '8108', 'protocol' => 'http']];

        $settings = $this->factory->resolveSettings(
            ['nodes' => []],
            ['nodes' => $fallbackNodes]
        );

        $this->assertSame($fallbackNodes, $settings['nodes']);
    }

    public function test_make_returns_a_client(): void
    {
        $client = $this->factory->make([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        $this->assertInstanceOf(Client::class, $client);
    }
}
