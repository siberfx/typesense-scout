<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use Siberfx\Typesense\Standalone\DocumentActions;
use Siberfx\Typesense\Standalone\TypesenseConnection;
use Siberfx\Typesense\Standalone\TypesenseManager;
use Typesense\Alias;
use Typesense\Aliases;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;

trait StandaloneHelpersTestHelpers
{
    protected function realClient(): Client
    {
        return new Client([
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
        ]);
    }

    /**
     * A client whose collections all hand out the given Documents stub, and
     * whose collection delete() calls are recorded in $dropped.
     */
    protected function clientWith(?Documents $documents = null, ?Aliases $aliases = null, array &$dropped = []): Client
    {
        $collections = $this->createStub(Collections::class);
        $collections->method('offsetGet')->willReturnCallback(function (string $name) use ($documents, &$dropped) {
            $collection = $this->createStub(Collection::class);
            $collection->method('getDocuments')->willReturn($documents ?? $this->createStub(Documents::class));
            $collection->method('delete')->willReturnCallback(function () use ($name, &$dropped) {
                $dropped[] = $name;

                return ['name' => $name];
            });

            return $collection;
        });

        $client = $this->createStub(Client::class);
        $client->method('getCollections')->willReturn($collections);
        $client->method('getAliases')->willReturn($aliases ?? $this->createStub(Aliases::class));

        return $client;
    }

    /**
     * Aliases stub: $existing maps alias => collection; upserts are recorded.
     */
    protected function aliases(array $existing, array &$upserts = []): Aliases
    {
        $aliases = $this->createStub(Aliases::class);
        $aliases->method('offsetGet')->willReturnCallback(function (string $name) use ($existing) {
            $alias = $this->createStub(Alias::class);

            if (array_key_exists($name, $existing)) {
                $alias->method('retrieve')->willReturn(['name' => $name, 'collection_name' => $existing[$name]]);
            } else {
                $alias->method('retrieve')->willThrowException(new ObjectNotFound('Not Found'));
            }

            return $alias;
        });
        $aliases->method('upsert')->willReturnCallback(function (string $name, array $mapping) use (&$upserts) {
            $upserts[] = [$name, $mapping];

            return ['name' => $name] + $mapping;
        });

        return $aliases;
    }

    /** Documents stub whose import() answers each document with $rowFor($document). */
    protected function importingDocuments(callable $rowFor, array &$batches = []): Documents
    {
        $documents = $this->createStub(Documents::class);
        $documents->method('import')->willReturnCallback(function (array $batch, array $options) use ($rowFor, &$batches) {
            $batches[] = [count($batch), $options];

            return array_map($rowFor, $batch);
        });

        return $documents;
    }
}

uses(StandaloneHelpersTestHelpers::class);

// ---- truncateCollection ------------------------------------------------

test('truncateCollection deletes with truncate and returns the count', function () {
    $documents = $this->createMock(Documents::class);
    $documents->expects($this->once())->method('delete')
        ->with(['truncate' => 'true'])
        ->willReturn(['num_deleted' => 42]);

    $connection = new TypesenseConnection($this->clientWith($documents));

    expect($connection->truncateCollection('books'))->toBe(42);
});

// ---- updateByFilter ----------------------------------------------------

test('updateByFilter sends the filter and returns the updated count', function () {
    $documents = $this->createMock(Documents::class);
    $documents->expects($this->once())->method('update')
        ->with(['in_stock' => false], ['filter_by' => 'year:<1950'])
        ->willReturn(['num_updated' => 7]);

    $actions = new DocumentActions($this->clientWith($documents), 'books');

    expect($actions->updateByFilter(['in_stock' => false], 'year:<1950'))->toBe(7);
});

// ---- aliasTarget / swapAlias -------------------------------------------

test('aliasTarget returns the collection an alias points to', function () {
    $connection = new TypesenseConnection($this->clientWith(aliases: $this->aliases(['books' => 'books_v1'])));

    expect($connection->aliasTarget('books'))->toBe('books_v1');
});

test('aliasTarget returns null for a missing alias', function () {
    $connection = new TypesenseConnection($this->clientWith(aliases: $this->aliases([])));

    expect($connection->aliasTarget('books'))->toBeNull();
});

test('swapAlias points the alias at the new collection and returns the previous one', function () {
    $upserts = [];
    $dropped = [];
    $connection = new TypesenseConnection(
        $this->clientWith(null, $this->aliases(['books' => 'books_v1'], $upserts), $dropped)
    );

    expect($connection->swapAlias('books', 'books_v2'))->toBe('books_v1');
    expect($upserts)->toBe([['books', ['collection_name' => 'books_v2']]]);
    expect($dropped)->toBe([]);
});

test('swapAlias drops the previous collection when asked', function () {
    $dropped = [];
    $connection = new TypesenseConnection(
        $this->clientWith(null, $this->aliases(['books' => 'books_v1']), $dropped)
    );

    $connection->swapAlias('books', 'books_v2', dropPrevious: true);

    expect($dropped)->toBe(['books_v1']);
});

test('swapAlias creates a new alias and drops nothing', function () {
    $upserts = [];
    $dropped = [];
    $connection = new TypesenseConnection(
        $this->clientWith(null, $this->aliases([], $upserts), $dropped)
    );

    expect($connection->swapAlias('books', 'books_v1', dropPrevious: true))->toBeNull();
    expect($upserts)->toHaveCount(1);
    expect($dropped)->toBe([]);
});

test('swapAlias never drops the collection it now points to', function () {
    $dropped = [];
    $connection = new TypesenseConnection(
        $this->clientWith(null, $this->aliases(['books' => 'books_v1']), $dropped)
    );

    $connection->swapAlias('books', 'books_v1', dropPrevious: true);

    expect($dropped)->toBe([]);
});

// ---- importChunked -----------------------------------------------------

test('importChunked imports a generator in chunks and totals the results', function () {
    $batches = [];
    $documents = $this->importingDocuments(fn (array $doc) => ['success' => true], $batches);
    $actions = new DocumentActions($this->clientWith($documents), 'books');

    $generator = (function () {
        for ($i = 1; $i <= 5; $i++) {
            yield ['id' => (string) $i];
        }
    })();

    $result = $actions->importChunked($generator, 'create', 2);

    expect($result)->toBe(['imported' => 5, 'failed' => 0, 'errors' => []]);
    expect($batches)->toBe([
        [2, ['action' => 'create']],
        [2, ['action' => 'create']],
        [1, ['action' => 'create']],
    ]);
});

test('importChunked reports failed rows by their position in the input', function () {
    $documents = $this->importingDocuments(fn (array $doc) => $doc['id'] === '4'
        ? ['success' => false, 'code' => 400, 'error' => 'Field `title` is missing.']
        : ['success' => true]);
    $actions = new DocumentActions($this->clientWith($documents), 'books');

    $result = $actions->importChunked([['id' => '1'], ['id' => '2'], ['id' => '3'], ['id' => '4']], chunkSize: 3);

    expect($result['imported'])->toBe(3);
    expect($result['failed'])->toBe(1);
    expect($result['errors'])->toBe([['row' => 3, 'code' => 400, 'error' => 'Field `title` is missing.']]);
});

test('importChunked caps the listed errors but counts every failure', function () {
    $documents = $this->importingDocuments(fn (array $doc) => ['success' => false, 'error' => 'bad']);
    $actions = new DocumentActions($this->clientWith($documents), 'books');

    $result = $actions->importChunked(array_fill(0, DocumentActions::IMPORT_ERROR_LIMIT + 5, ['id' => 'x']), chunkSize: 50);

    expect($result['failed'])->toBe(DocumentActions::IMPORT_ERROR_LIMIT + 5);
    expect($result['errors'])->toHaveCount(DocumentActions::IMPORT_ERROR_LIMIT);
});

test('importChunked sends nothing for no documents', function () {
    $batches = [];
    $documents = $this->importingDocuments(fn (array $doc) => ['success' => true], $batches);
    $actions = new DocumentActions($this->clientWith($documents), 'books');

    expect($actions->importChunked([]))->toBe(['imported' => 0, 'failed' => 0, 'errors' => []]);
    expect($batches)->toBe([]);
});

test('importChunked rejects a chunk size below one', function () {
    $actions = new DocumentActions($this->realClient(), 'books');

    expect(fn () => $actions->importChunked([['id' => '1']], chunkSize: 0))
        ->toThrow(\InvalidArgumentException::class);
});

// ---- browserCredentials ------------------------------------------------

test('browserCredentials bundles a scoped key, the public node and the expiry', function () {
    $connection = new TypesenseConnection($this->realClient(), [
        'host' => 'search.example.com', 'port' => '443', 'protocol' => 'https',
    ]);

    $before = time();
    $credentials = $connection->browserCredentials('parentKey', ['filter_by' => 'tenant:7'], 600);

    expect($credentials['host'])->toBe('search.example.com');
    expect($credentials['port'])->toBe(443);
    expect($credentials['protocol'])->toBe('https');
    expect($credentials['path'])->toBe('');
    expect($credentials['expires_at'])->toBeGreaterThanOrEqual($before + 600)->toBeLessThanOrEqual(time() + 600);

    // The embedded parameters carry the filter and the same expiry.
    $embedded = json_decode(substr(base64_decode($credentials['key']), 44 + 4), true);
    expect($embedded)->toBe(['filter_by' => 'tenant:7', 'expires_at' => $credentials['expires_at']]);
});

test('browserCredentials without a ttl keeps the given expires_at', function () {
    $connection = new TypesenseConnection($this->realClient(), ['host' => 'localhost']);

    $credentials = $connection->browserCredentials('parentKey', ['expires_at' => 1900000000], null);

    expect($credentials['expires_at'])->toBe(1900000000);
    expect($credentials['port'])->toBeNull();
});

test('the manager uses the public settings for browser credentials', function () {
    $manager = TypesenseManager::fromConfig([
        'default' => 'default',
        'connections' => ['default' => [
            'api_key' => 'xyz',
            'nodes' => [['host' => 'typesense.internal', 'port' => '8108', 'protocol' => 'http']],
            'public' => ['host' => 'search.example.com', 'port' => null, 'protocol' => 'https', 'path' => ''],
        ]],
    ]);

    $credentials = $manager->connection()->browserCredentials('parentKey');

    expect($credentials['host'])->toBe('search.example.com');
    expect($credentials['port'])->toBe(8108);
    expect($credentials['protocol'])->toBe('https');
});

test('the manager falls back to the first node without public settings', function () {
    $manager = TypesenseManager::fromConfig([
        'default' => 'default',
        'connections' => ['default' => [
            'api_key' => 'xyz',
            'nodes' => [['host' => 'typesense.internal', 'port' => '8108', 'protocol' => 'http']],
            'public' => ['host' => null, 'port' => null, 'protocol' => null, 'path' => ''],
        ]],
    ]);

    $credentials = $manager->connection()->browserCredentials('parentKey');

    expect($credentials['host'])->toBe('typesense.internal');
    expect($credentials['port'])->toBe(8108);
    expect($credentials['protocol'])->toBe('http');
});

test('the manager keeps public settings out of the client settings', function () {
    $manager = TypesenseManager::fromConfig([
        'default' => 'default',
        'connections' => ['default' => [
            'api_key' => 'xyz',
            'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
            'public' => ['host' => 'search.example.com'],
        ]],
    ]);

    expect($manager->connection())->toBeInstanceOf(TypesenseConnection::class);
});
