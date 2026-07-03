<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\AnalyticsV1;
use Typesense\Client;
use Typesense\CurationSets;
use Typesense\SynonymSets;

class TypesenseConnectionV6Test extends TestCase
{
    private function connection(): TypesenseConnection
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        return new TypesenseConnection($client);
    }

    public function test_exposes_global_synonym_sets(): void
    {
        $this->assertInstanceOf(SynonymSets::class, $this->connection()->synonymSets());
    }

    public function test_exposes_global_curation_sets(): void
    {
        $this->assertInstanceOf(CurationSets::class, $this->connection()->curationSets());
    }

    public function test_exposes_analytics_v1(): void
    {
        $this->assertInstanceOf(AnalyticsV1::class, $this->connection()->analyticsV1());
    }
}
