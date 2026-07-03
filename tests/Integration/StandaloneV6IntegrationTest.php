<?php

namespace Siberfx\Typesense\Tests\Integration;

use Siberfx\Typesense\Standalone\TypesenseManager;

class StandaloneV6IntegrationTest extends IntegrationTestCase
{
    private function connection()
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

    private function deleteSynonymSet(string $name): void
    {
        try {
            $this->client->getSynonymSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    private function deleteCurationSet(string $name): void
    {
        try {
            $this->client->getCurationSets()[$name]->delete();
        } catch (\Throwable $e) {
            // not there — nothing to clean up
        }
    }

    public function test_global_synonym_sets_lifecycle(): void
    {
        $conn = $this->connection();
        $name = 'standalone_syn';
        $this->deleteSynonymSet($name);

        $conn->synonymSets()->upsert($name, [
            'items' => [
                ['id' => 'coats', 'synonyms' => ['blazer', 'coat', 'jacket']],
            ],
        ]);

        $all = $conn->synonymSets()->retrieve();
        $this->assertNotEmpty($all);

        $one = $conn->synonymSets()[$name]->retrieve();
        $this->assertSame($name, $one['name'] ?? $name);

        $this->deleteSynonymSet($name);
    }

    public function test_global_curation_sets_lifecycle(): void
    {
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
        $this->assertNotEmpty($all);

        $this->deleteCurationSet($name);
    }
}
