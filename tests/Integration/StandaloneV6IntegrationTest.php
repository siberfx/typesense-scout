<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseManager;

uses(IntegrationServer::class);

beforeEach(function () {
    $this->connectToIntegrationServer();
});
trait StandaloneV6IntegrationTestHelpers
{
    protected function connection()
    {
        return TypesenseManager::fromConfig([
            'default' => 'default',
            'connections' => [
                'default' => [
                    'api_key' => getenv('TYPESENSE_API_KEY') ?: 'xyz',
                    'nodes' => [[
                        'host' => getenv('TYPESENSE_HOST') ?: 'localhost',
                        'port' => getenv('TYPESENSE_PORT') ?: '8108',
                        'protocol' => getenv('TYPESENSE_PROTOCOL') ?: 'http',
                    ]],
                    'connection_timeout_seconds' => 2,
                ],
            ],
        ])->connection();
    }

    protected function deleteSynonymSet(string $name): void
    {
        try {
            $this->client->getSynonymSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    protected function deleteCurationSet(string $name): void
    {
        try {
            $this->client->getCurationSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }
}

uses(StandaloneV6IntegrationTestHelpers::class);

test('global synonym sets lifecycle', function () {
    $conn = $this->connection();
    $name = 'standalone_syn';
    $this->deleteSynonymSet($name);

    $conn->synonymSets()->upsert($name, [
        'items' => [
            ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
        ],
    ]);

    $all = $conn->synonymSets()->retrieve();
    expect($all)->not->toBeEmpty();

    $one = $conn->synonymSets()[$name]->retrieve();
    expect($one['name'] ?? $name)->toBe($name);

    $this->deleteSynonymSet($name);
});

test('global curation sets lifecycle', function () {
    $conn = $this->connection();
    $name = 'standalone_cur';
    $this->deleteCurationSet($name);

    $conn->curationSets()->upsert($name, [
        'items' => [
            [
                'id'       => 'promo',
                'rule'     => ['query' => 'apple', 'match' => 'exact'],
                'includes' => [['id' => '422', 'position' => 1]],
                'excludes' => [['id' => '287']],
            ],
        ],
    ]);

    $all = $conn->curationSets()->retrieve();
    expect($all)->not->toBeEmpty();

    $this->deleteCurationSet($name);
});
