<?php

namespace Siberfx\Typesense\Standalone;

use Typesense\Client;
use Typesense\Documents;

/**
 * Document operations bound to a single collection. Returned by
 * TypesenseConnection::documents(). Thin delegations to \Typesense\Documents.
 */
class DocumentActions
{
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
     * Bulk import. Accepts an array of associative docs or a JSONL string.
     *
     * @param iterable|string $documents
     * @param string          $action    create|upsert|update|emplace
     * @return array The per-line import results.
     */
    public function import($documents, string $action = 'upsert'): array
    {
        return $this->documents()->import($documents, ['action' => $action]);
    }

    public function export(array $params = []): string
    {
        return $this->documents()->export($params);
    }

    private function documents(): Documents
    {
        return $this->client->getCollections()[$this->collection]->getDocuments();
    }
}
