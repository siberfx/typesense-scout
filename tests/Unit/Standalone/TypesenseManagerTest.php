<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use InvalidArgumentException;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;

trait TypesenseManagerTestHelpers
{
    protected function config(): array
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

    protected function scoutFallback(): array
    {
        return [
            'api_key' => 'scout-key',
            'nodes' => [['host' => 'scout-host', 'port' => '8108', 'protocol' => 'http']],
        ];
    }
}

uses(TypesenseManagerTestHelpers::class);

test('default connection merges scout fallback', function () {
    $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

    $settings = $manager->resolveSettingsFor('default');

    expect($settings['api_key'])->toBe('scout-key');
    expect($settings['nodes'][0]['host'])->toBe('scout-host');
});

test('named connection does not get scout fallback', function () {
    $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

    $settings = $manager->resolveSettingsFor('analytics');

    expect($settings['api_key'])->toBe('analytics-key');
    expect($settings['nodes'][0]['host'])->toBe('a-host');
});

test('unknown connection throws', function () {
    expect(function () {
        $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

        $manager->connection('does-not-exist');
    })->toThrow(InvalidArgumentException::class);
});

test('connection is cached and default resolves', function () {
    $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

    expect($manager->connection())->toBeInstanceOf(TypesenseConnection::class);
    expect($manager->connection('default'))->toBe($manager->connection());
});

test('call proxies to default connection', function () {
    $manager = TypesenseManager::fromConfig($this->config(), $this->scoutFallback());

    // client() is network-free; proves __call routes to the default connection.
    expect($manager->client())->toBe($manager->connection('default')->client());
});
