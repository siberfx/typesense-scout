<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;

class TypesenseManagerTest extends TestCase
{
    private function config(): array
    {
        return [
            'default' => 'default',
            'connections' => [
                'default' => ['api_key' => null, 'nodes' => []],
                'analytics' => [
                    'api_key' => 'analytics-key',
                    'nodes' => [['host' => 'a-host', 'port' => '8108', 'protocol' => 'http']],
                ],
            ],
        ];
    }

    private function scoutFallback(): array
    {
        return [
            'api_key' => 'scout-key',
            'nodes' => [['host' => 'scout-host', 'port' => '8108', 'protocol' => 'http']],
        ];
    }

    public function test_default_connection_merges_scout_fallback(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $settings = $manager->resolveSettingsFor('default');

        $this->assertSame('scout-key', $settings['api_key']);
        $this->assertSame('scout-host', $settings['nodes'][0]['host']);
    }

    public function test_named_connection_does_not_get_scout_fallback(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $settings = $manager->resolveSettingsFor('analytics');

        $this->assertSame('analytics-key', $settings['api_key']);
        $this->assertSame('a-host', $settings['nodes'][0]['host']);
    }

    public function test_unknown_connection_throws(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $this->expectException(InvalidArgumentException::class);
        $manager->connection('does-not-exist');
    }

    public function test_connection_is_cached_and_default_resolves(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $this->assertInstanceOf(TypesenseConnection::class, $manager->connection());
        $this->assertSame($manager->connection(), $manager->connection('default'));
    }

    public function test_call_proxies_to_default_connection(): void
    {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        // client() is network-free; proves __call routes to the default connection.
        $this->assertSame(
            $manager->connection('default')->client(),
            $manager->client()
        );
    }
}
