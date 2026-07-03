<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;

class StandaloneConfigTest extends TestCase
{
    private function config(): array
    {
        return require __DIR__ . '/../../../config/typesense.php';
    }

    public function test_has_default_connection_name(): void
    {
        $config = $this->config();

        $this->assertArrayHasKey('default', $config);
        $this->assertArrayHasKey('connections', $config);
    }

    public function test_default_connection_defines_expected_keys(): void
    {
        $connections = $this->config()['connections'];

        $this->assertArrayHasKey('default', $connections);
        $this->assertArrayHasKey('api_key', $connections['default']);
        $this->assertArrayHasKey('nodes', $connections['default']);
    }
}
