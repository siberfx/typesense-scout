<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\AnalyticsV1;
use Typesense\Client;
use Typesense\CurationSets;
use Typesense\SynonymSets;

trait TypesenseConnectionV6TestHelpers
{
    protected function connection(): TypesenseConnection
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        return new TypesenseConnection($client);
    }
}

uses(TypesenseConnectionV6TestHelpers::class);

test('exposes global synonym sets', function () {
    expect($this->connection()->synonymSets())->toBeInstanceOf(SynonymSets::class);
});

test('exposes global curation sets', function () {
    expect($this->connection()->curationSets())->toBeInstanceOf(CurationSets::class);
});

test('exposes analytics v1', function () {
    expect($this->connection()->analyticsV1())->toBeInstanceOf(AnalyticsV1::class);
});
