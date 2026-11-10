<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

trait StandaloneConfigTestHelpers
{
    protected function config(): array
    {
        return require __DIR__ . '/../../../config/typesense.php';
    }
}

uses(StandaloneConfigTestHelpers::class);

test('has default connection name', function () {
    $config = $this->config();

    expect($config)->toHaveKey('default');
    expect($config)->toHaveKey('connections');
});

test('default connection defines expected keys', function () {
    $connections = $this->config()['connections'];

    expect($connections)->toHaveKey('default');
    expect($connections['default'])->toHaveKey('api_key');
    expect($connections['default'])->toHaveKey('nodes');
});
