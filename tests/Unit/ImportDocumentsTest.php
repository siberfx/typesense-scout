<?php

namespace Siberfx\Typesense\Tests\Unit;

use Siberfx\Typesense\Typesense;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Documents;
use Typesense\Exceptions\TypesenseClientError;

trait ImportDocumentsTestHelpers
{
    protected function typesense(array $importResponse): array
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
}

uses(ImportDocumentsTestHelpers::class);

test('returns one response per imported document', function () {
    [$typesense, $collection] = $this->typesense([
        ['success' => true, 'code' => 200],
        ['success' => true, 'code' => 200],
    ]);

    $responses = $typesense->importDocuments($collection, [['id' => '1'], ['id' => '2']]);

    expect($responses)->toHaveCount(2);
});

test('reports every failed row not only the first', function () {
    [$typesense, $collection] = $this->typesense([
        ['success' => false, 'error' => 'Field `title` must be a string.', 'code' => 400],
        ['success' => true, 'code' => 200],
        ['success' => false, 'error' => 'Field `title` is missing.', 'code' => 400],
    ]);

    expect(fn () => $typesense->importDocuments($collection, [['id' => '1'], ['id' => '2'], ['id' => '3']]))
        ->toThrow(function (TypesenseClientError $e) {
            expect($e->getMessage())->toContain('2 of 3');
            expect($e->getMessage())->toContain('row 0: Field `title` must be a string.');
            expect($e->getMessage())->toContain('row 2: Field `title` is missing.');
        });
});

test('limits the listed failures and counts the rest', function () {
    $rows = array_fill(0, 12, ['success' => false, 'error' => 'bad', 'code' => 400]);
    [$typesense, $collection] = $this->typesense($rows);

    expect(fn () => $typesense->importDocuments($collection, array_fill(0, 12, ['id' => 'x'])))
        ->toThrow(function (TypesenseClientError $e) {
            expect($e->getMessage())->toContain('12 of 12');
            expect($e->getMessage())->toContain('and 2 more');
        });
});
