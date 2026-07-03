<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Standalone\DocumentActions;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Typesense\Client;

class TypesenseConnectionTest extends TestCase
{
    private function connection(): TypesenseConnection
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);

        return new TypesenseConnection($client);
    }

    public function test_client_returns_injected_client(): void
    {
        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);
        $connection = new TypesenseConnection($client);

        $this->assertSame($client, $connection->client());
    }

    public function test_documents_returns_bound_document_actions(): void
    {
        $actions = $this->connection()->documents('books');

        $this->assertInstanceOf(DocumentActions::class, $actions);
        $this->assertSame('books', $actions->collection());
    }
}
