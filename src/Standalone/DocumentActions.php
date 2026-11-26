<?php

namespace Siberfx\Typesense\Standalone;

use InvalidArgumentException;
use Typesense\Client;
use Typesense\Documents;

/**
 * Document operations bound to a single collection. Returned by
 * TypesenseConnection::documents(). Thin delegations to \Typesense\Documents.
 */
class DocumentActions
{
    /** The most failed rows importChunked() lists in its `errors`. */
    public const IMPORT_ERROR_LIMIT = 100;

    public function __construct(
        private readonly Client $client,
        private readonly string $collection,
    ) {
    }

    public function collection(): string
    {
        return $this->collection;
    }

    public function create(array $document, array $options = []): array
    {
        return $this->documents()->create($document, $options);
    }

    public function upsert(array $document, array $options = []): array
    {
        return $this->documents()->upsert($document, $options);
    }

    public function update(array $document, array $options = []): array
    {
        return $this->documents()->update($document, $options);
    }

    public function retrieve(string $id): array
    {
        return $this->documents()[$id]->retrieve();
    }

    public function delete(string $id): array
    {
        return $this->documents()[$id]->delete();
    }

    /**
     * Delete every document matching a filter, e.g. ['filter_by' => 'year:<1950'].
     */
    public function deleteByFilter(array $query): array
    {
        return $this->documents()->delete($query);
    }

    /**
     * Partially update every document matching a filter, e.g.
     * updateByFilter(['in_stock' => false], 'year:<1950').
     *
     * @return int The number of updated documents.
     */
    public function updateByFilter(array $fields, string $filterBy): int
    {
        $result = $this->documents()->update($fields, ['filter_by' => $filterBy]);

        return (int) ($result['num_updated'] ?? 0);
    }

    /**
     * Bulk import. Accepts an array of associative docs or a JSONL string.
     *
     * @param iterable|string $documents
     * @param string          $action    create|upsert|update|emplace
     * @return array|string The per-line import results.
     */
    public function import($documents, string $action = 'upsert'): array|string
    {
        return $this->documents()->import($documents, ['action' => $action]);
    }

    /**
     * Import documents in chunks, so a generator or lazy collection never has
     * to sit in memory at once. Failed rows do not stop the import.
     *
     * @param iterable $documents Associative documents, e.g. a generator.
     * @param string   $action    create|upsert|update|emplace
     * @param int      $chunkSize Documents per import request.
     * @return array{imported: int, failed: int, errors: list<array{row: int, code: int|null, error: string}>}
     *               `row` is the document's position in $documents. At most
     *               IMPORT_ERROR_LIMIT errors are listed; `failed` counts them all.
     */
    public function importChunked(iterable $documents, string $action = 'upsert', int $chunkSize = 100): array
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size must be at least 1.');
        }

        $totals = ['imported' => 0, 'failed' => 0, 'errors' => []];
        $chunk = [];
        $offset = 0;

        foreach ($documents as $document) {
            $chunk[] = $document;

            if (count($chunk) === $chunkSize) {
                $totals = $this->importChunk($chunk, $action, $offset, $totals);
                $offset += $chunkSize;
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            $totals = $this->importChunk($chunk, $action, $offset, $totals);
        }

        return $totals;
    }

    public function export(array $params = []): string
    {
        return $this->documents()->export($params);
    }

    private function importChunk(array $chunk, string $action, int $offset, array $totals): array
    {
        foreach ($this->import($chunk, $action) as $index => $row) {
            if (($row['success'] ?? false) === true) {
                $totals['imported']++;

                continue;
            }

            $totals['failed']++;

            if (count($totals['errors']) < self::IMPORT_ERROR_LIMIT) {
                $totals['errors'][] = [
                    'row' => $offset + $index,
                    'code' => isset($row['code']) ? (int) $row['code'] : null,
                    'error' => (string) ($row['error'] ?? 'Unknown import error.'),
                ];
            }
        }

        return $totals;
    }

    private function documents(): Documents
    {
        return $this->client->getCollections()[$this->collection]->getDocuments();
    }
}
