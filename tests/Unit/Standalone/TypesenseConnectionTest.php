<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use Siberfx\Typesense\Standalone\DocumentActions;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\Client;

trait TypesenseConnectionTestHelpers
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

uses(TypesenseConnectionTestHelpers::class);

test('client returns injected client', function () {
    $client = new Client([
        'api_key' => 'xyz',
        'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
    ]);
    $connection = new TypesenseConnection($client);

    expect($connection->client())->toBe($client);
});

test('documents returns bound document actions', function () {
    $actions = $this->connection()->documents('books');

    expect($actions)->toBeInstanceOf(DocumentActions::class);
    expect($actions->collection())->toBe('books');
});
