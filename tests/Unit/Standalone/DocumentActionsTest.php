<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use Siberfx\Typesense\Standalone\DocumentActions;
use Typesense\Client;

trait DocumentActionsTestHelpers
{
    protected function client(): Client
    {
        return new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);
    }
}

uses(DocumentActionsTestHelpers::class);

test('exposes bound collection name', function () {
    $actions = new DocumentActions($this->client(), 'books');

    expect($actions->collection())->toBe('books');
});
