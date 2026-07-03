<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\DocumentActions;
use Typesense\Client;

class DocumentActionsTest extends TestCase
{
    private function client(): Client
    {
        return new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);
    }

    public function test_exposes_bound_collection_name(): void
    {
        $actions = new DocumentActions($this->client(), 'books');

        $this->assertSame('books', $actions->collection());
    }
}
