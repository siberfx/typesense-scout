<?php

namespace Siberfx\Typesense\Tests\Unit;

/**
 * Guards the published config shape against the keys the package code reads.
 * The service provider and Typesense class read
 * `scout.typesense.client-settings`, so that key must exist.
 */

trait ScoutConfigTestHelpers
{
    protected function config(): array
    {
        return require __DIR__ . '/../../config/scout.php';
    }
}

uses(ScoutConfigTestHelpers::class);

test('typesense client settings key exists', function () {
    $config = $this->config();

    expect($config)->toHaveKey('typesense');
    expect($config['typesense'])->toHaveKey('client-settings');
});

test('the template escapes where values by default', function () {
    $typesense = $this->config()['typesense'];

    expect($typesense)->toHaveKey('escape_filter_values');
    expect($typesense['escape_filter_values'])->toBeTrue();
});

test('the template does not default the api key to a placeholder', function () {
    // A placeholder key would be sent to the server and fail with a 401 later.
    // Reading the source keeps the test independent of TYPESENSE_API_KEY in the environment.
    $source = file_get_contents(__DIR__ . '/../../config/scout.php');

    expect($source)->not->toContain("'xyz'");
});

test('client settings contain connection details', function () {
    $clientSettings = $this->config()['typesense']['client-settings'];

    expect($clientSettings)->toHaveKey('api_key');
    expect($clientSettings)->toHaveKey('nodes');
    expect($clientSettings['nodes'])->not->toBeEmpty();
    expect($clientSettings['nodes'][0])->toHaveKey('host');
});
