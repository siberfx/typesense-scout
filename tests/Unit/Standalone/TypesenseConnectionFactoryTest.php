<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use Siberfx\Typesense\Standalone\TypesenseConnectionFactory;
use Typesense\Client;

beforeEach(function () {
        $this->factory = new TypesenseConnectionFactory();
});

test('connection value overrides fallback', function () {
    $settings = $this->factory->resolveSettings(
        ['api_key' => 'conn-key'],
        ['api_key' => 'scout-key', 'num_retries' => 3]
    );

    expect($settings['api_key'])->toBe('conn-key');
    expect($settings['num_retries'])->toBe(3); // fallback preserved
});

test('null connection value falls back', function () {
    $settings = $this->factory->resolveSettings(
        ['api_key' => null],
        ['api_key' => 'scout-key']
    );

    expect($settings['api_key'])->toBe('scout-key');
});

test('empty nodes falls back', function () {
    $fallbackNodes = [['host' => 'scout-host', 'port' => '8108', 'protocol' => 'http']];

    $settings = $this->factory->resolveSettings(
        ['nodes' => []],
        ['nodes' => $fallbackNodes]
    );

    expect($settings['nodes'])->toBe($fallbackNodes);
});

test('make returns a client', function () {
    $client = $this->factory->make([
        'api_key' => 'xyz',
        'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
    ]);

    expect($client)->toBeInstanceOf(Client::class);
});
