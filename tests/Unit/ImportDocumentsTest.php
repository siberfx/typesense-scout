<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Typesense;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Documents;
use Typesense\Exceptions\TypesenseClientError;

class ImportDocumentsTest extends TestCase
{
    private function typesense(array $importResponse): array
    {
        $documents = $this->createStub(Documents::class);
        $documents->method('import')->willReturn($importResponse);

        $collection = $this->createStub(Collection::class);
        $collection->method('getDocuments')->willReturn($documents);

        $client = new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
            'connection_timeout_seconds' => 2,
        ]);

        return [new Typesense($client), $collection];
    }

    public function test_returns_one_response_per_imported_document(): void
    {
        [$typesense, $collection] = $this->typesense([
            ['success' => true, 'code' => 200],
            ['success' => true, 'code' => 200],
        ]);

        $responses = $typesense->importDocuments($collection, [['id' => '1'], ['id' => '2']]);

        $this->assertCount(2, $responses);
    }

    public function test_reports_every_failed_row_not_only_the_first(): void
    {
        [$typesense, $collection] = $this->typesense([
            ['success' => false, 'error' => 'Field `title` must be a string.', 'code' => 400],
            ['success' => true, 'code' => 200],
            ['success' => false, 'error' => 'Field `title` is missing.', 'code' => 400],
        ]);

        try {
            $typesense->importDocuments($collection, [['id' => '1'], ['id' => '2'], ['id' => '3']]);
            $this->fail('A failed import must throw.');
        } catch (TypesenseClientError $e) {
            $this->assertStringContainsString('2 of 3', $e->getMessage());
            $this->assertStringContainsString('row 0: Field `title` must be a string.', $e->getMessage());
            $this->assertStringContainsString('row 2: Field `title` is missing.', $e->getMessage());
        }
    }

    public function test_limits_the_listed_failures_and_counts_the_rest(): void
    {
        $rows = array_fill(0, 12, ['success' => false, 'error' => 'bad', 'code' => 400]);
        [$typesense, $collection] = $this->typesense($rows);

        try {
            $typesense->importDocuments($collection, array_fill(0, 12, ['id' => 'x']));
            $this->fail('A failed import must throw.');
        } catch (TypesenseClientError $e) {
            $this->assertStringContainsString('12 of 12', $e->getMessage());
            $this->assertStringContainsString('and 2 more', $e->getMessage());
        }
    }
}
