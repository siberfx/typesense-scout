<?php

namespace Siberfx\Typesense\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Exceptions\ScoutException;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Collection;

/**
 * Scout semantic() / hybrid() support, ported from laravel/scout 11.7's
 * TypesenseEngine tests and adapted to this engine.
 */

trait SemanticSearchTestHelpers
{
    protected const GENERATED = ['driver' => 'laravel-ai', 'attribute' => 'embedding', 'dimensions' => 2];

    protected const NATIVE = ['driver' => 'typesense', 'attribute' => 'embedding'];

    /**
     * An engine whose embedding generation is faked and recorded.
     */
    protected function engine(array $embedding, array $config = [], ?Typesense $typesense = null): TypesenseEngine
    {
        $typesense ??= $this->createStub(Typesense::class);

        return new class($typesense, ['model-settings' => [SemanticModel::class => ['embedding' => $embedding]]] + $config) extends TypesenseEngine {
            public array $embedded = [];

            protected function generateEmbeddings(array $inputs, array $settings): array
            {
                $this->embedded[] = $inputs;

                return array_map(static fn (string $input): array => [strlen($input) / 100, 0.5], $inputs);
            }
        };
    }

    protected function params(TypesenseEngine $engine, Builder $builder): array
    {
        $ref = new ReflectionClass(TypesenseEngine::class);

        return $ref->getMethod('filterSearchParams')->invoke(
            $engine,
            $ref->getMethod('buildSearchParams')->invoke($engine, $builder, 1, 10)
        );
    }

    protected function builder(string $query, array $queryBy = ['name']): Builder
    {
        $model = new SemanticModel();
        $model->queryBy = $queryBy;

        return new Builder($model, $query);
    }
}

uses(SemanticSearchTestHelpers::class);

test('engine supports semantic search', function () {
    expect($this->engine(self::NATIVE))->toBeInstanceOf(SupportsSemanticSearch::class);
});

test('semantic search generates a query vector', function () {
    $engine = $this->engine(self::GENERATED);

    $params = $this->params($engine, $this->builder('red shoes')->semantic(0.7));

    expect($params['q'])->toBe('*');
    expect($params['query_by'])->toBe('name');
    expect($params['vector_query'])->toBe('embedding:([0.09, 0.5], distance_threshold: 0.3)');
    expect($params['exclude_fields'])->toBe('embedding');
    expect($engine->embedded)->toBe([['red shoes']]);
});

test('hybrid search accepts a precomputed query vector and normalizes weights', function () {
    $engine = $this->engine(self::GENERATED);

    $builder = $this->builder('red shoes')->options(['vector' => [0.4, 0.6]])->hybrid(1, 2);
    $params = $this->params($engine, $builder);

    expect($params['q'])->toBe('red shoes');
    expect($params['query_by'])->toBe('name');
    expect($params['vector_query'])->toBe('embedding:([0.4, 0.6], alpha: ' . (2 / 3) . ')');
    expect($engine->embedded)->toBe([]);
});

test('semantic search with native embeddings queries the embedding field', function () {
    $params = $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->semantic());

    expect($params['q'])->toBe('red shoes');
    expect($params['query_by'])->toBe('embedding');
    expect($params['prefix'])->toBeFalse();
    expect($params)->not->toHaveKey('vector_query');
    expect($params['exclude_fields'])->toBe('embedding');
});

test('semantic search with native embeddings applies a distance threshold', function () {
    $params = $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->semantic(0.5));

    expect($params['vector_query'])->toBe('embedding:([], distance_threshold: 0.5)');
});

test('hybrid search with native embeddings appends the embedding field', function () {
    $params = $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->hybrid());

    expect($params['query_by'])->toBe('name,embedding');
    expect($params['prefix'])->toBe('true,false');
    expect($params['vector_query'])->toBe('embedding:([], alpha: 0.5)');
});

test('hybrid search with native embeddings extends per field lists', function () {
    $builder = $this->builder('red shoes', ['name', 'sku'])->hybrid();
    $builder->setPrefix('true,false')->setInfix('always,off');

    $params = $this->params($this->engine(self::NATIVE), $builder);

    expect($params['query_by'])->toBe('name,sku,embedding');
    expect($params['prefix'])->toBe('true,false,false');
    expect($params['infix'])->toBe('always,off,off');
});

test('existing exclude fields are kept', function () {
    $builder = $this->builder('red shoes')->semantic()->setExcludeFields(['secret']);

    $params = $this->params($this->engine(self::NATIVE), $builder);

    expect($params['exclude_fields'])->toBe('secret,embedding');
});

test('hybrid search requires a keyword field', function () {
    expect(function () {
        $this->params($this->engine(self::NATIVE), $this->builder('red shoes', ['embedding'])->hybrid());
    })->toThrow(ScoutException::class, 'Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');
});

test('semantic search cannot be combined with a manual vector query', function () {
    expect(function () {
        $builder = $this->builder('red shoes')->semantic()->nearestNeighbors('embedding', [0.1]);


        $this->params($this->engine(self::NATIVE), $builder);
    })->toThrow(ScoutException::class, 'cannot be combined with vectorQuery() or nearestNeighbors()');
});

test('semantic search requires embedding settings', function () {
    expect(function () {
        $engine = new TypesenseEngine($this->createStub(Typesense::class));


        $this->params($engine, $this->builder('red shoes')->semantic());
    })->toThrow(ScoutException::class, 'No Typesense embedding settings have been configured for [' . SemanticModel::class . '].');
});

test('generated embeddings require dimensions', function () {
    expect(function () {
        $this->params($this->engine(['attribute' => 'embedding']), $this->builder('red shoes')->semantic());
    })->toThrow(ScoutException::class, 'Typesense embedding settings must contain positive [dimensions].');
});

test('minimum similarity must be between zero and one', function () {
    expect(function () {
        $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->semantic(1.5));
    })->toThrow(ScoutException::class, 'The minimum similarity must be between 0 and 1.');
});

test('model method settings take precedence over config', function () {
    $engine = $this->engine(['attribute' => 'from_config', 'driver' => 'typesense']);

    $builder = $this->builder('red shoes')->semantic();
    $builder->model->embeddingSettings = ['attribute' => 'from_model', 'driver' => 'typesense'];

    expect($this->params($engine, $builder)['query_by'])->toBe('from_model');
});

test('update adds precomputed and generated embeddings to documents', function () {
    $typesense = $this->createMock(Typesense::class);
    $collection = $this->createStub(Collection::class);
    $typesense->method('getCollectionIndex')->willReturn($collection);
    $typesense->method('withCollection')->willReturnCallback(fn ($model, $operation) => $operation($collection));
    $typesense->expects($this->once())
        ->method('importDocuments')
        ->with($this->anything(), [
            ['id' => 1, 'name' => 'abcd', 'embedding' => [0.04, 0.5]],
            ['id' => 2, 'name' => 'precomputed', 'embedding' => [0.9, 0.1]],
        ]);

    $engine = $this->engine(self::GENERATED, [], $typesense);

    $engine->update(new EloquentCollection([
        SemanticModel::fake(1, 'abcd'),
        SemanticModel::fake(2, 'precomputed', [0.9, 0.1]),
    ]));

    expect($engine->embedded)->toBe([['abcd']]);
});

test('update does not generate embeddings with native driver', function () {
    $typesense = $this->createMock(Typesense::class);
    $collection = $this->createStub(Collection::class);
    $typesense->method('getCollectionIndex')->willReturn($collection);
    $typesense->method('withCollection')->willReturnCallback(fn ($model, $operation) => $operation($collection));
    $typesense->expects($this->once())
        ->method('importDocuments')
        ->with($this->anything(), [['id' => 1, 'name' => 'abcd']]);

    $engine = $this->engine(self::NATIVE, [], $typesense);
    $engine->update(new EloquentCollection([SemanticModel::fake(1, 'abcd')]));

    expect($engine->embedded)->toBe([]);
});

class SemanticModel extends Model
{
    public array $queryBy = ['name'];

    public ?array $embeddingSettings = null;

    public ?array $precomputed = null;

    public static function fake(int $id, string $name, ?array $precomputed = null): self
    {
        $model = new self();
        $model->id = $id;
        $model->name = $name;
        $model->precomputed = $precomputed;

        return $model;
    }

    public function typesenseQueryBy(): array
    {
        return $this->queryBy;
    }

    public function typesenseEmbeddingSettings(): ?array
    {
        return $this->embeddingSettings;
    }

    public function toSearchableArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }

    public function toSearchableEmbedding(): array|string
    {
        return $this->precomputed ?? $this->name;
    }
}
