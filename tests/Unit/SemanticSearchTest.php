<?php

namespace Siberfx\Typesense\Tests\Unit;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Exceptions\ScoutException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Collection;

/**
 * Scout semantic() / hybrid() support, ported from laravel/scout 11.7's
 * TypesenseEngine tests and adapted to this engine.
 */
class SemanticSearchTest extends TestCase
{
    /**
     * An engine whose embedding generation is faked and recorded.
     */
    private function engine(array $embedding, array $config = [], ?Typesense $typesense = null): TypesenseEngine
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

    private function params(TypesenseEngine $engine, Builder $builder): array
    {
        $ref = new ReflectionClass(TypesenseEngine::class);

        return $ref->getMethod('filterSearchParams')->invoke(
            $engine,
            $ref->getMethod('buildSearchParams')->invoke($engine, $builder, 1, 10)
        );
    }

    private function builder(string $query, array $queryBy = ['name']): Builder
    {
        $model = new SemanticModel();
        $model->queryBy = $queryBy;

        return new Builder($model, $query);
    }

    private const GENERATED = ['driver' => 'laravel-ai', 'attribute' => 'embedding', 'dimensions' => 2];

    private const NATIVE = ['driver' => 'typesense', 'attribute' => 'embedding'];

    public function test_engine_supports_semantic_search(): void
    {
        $this->assertInstanceOf(SupportsSemanticSearch::class, $this->engine(self::NATIVE));
    }

    public function test_semantic_search_generates_a_query_vector(): void
    {
        $engine = $this->engine(self::GENERATED);

        $params = $this->params($engine, $this->builder('red shoes')->semantic(0.7));

        $this->assertSame('*', $params['q']);
        $this->assertSame('name', $params['query_by']);
        $this->assertSame('embedding:([0.09, 0.5], distance_threshold: 0.3)', $params['vector_query']);
        $this->assertSame('embedding', $params['exclude_fields']);
        $this->assertSame([['red shoes']], $engine->embedded);
    }

    public function test_hybrid_search_accepts_a_precomputed_query_vector_and_normalizes_weights(): void
    {
        $engine = $this->engine(self::GENERATED);

        $builder = $this->builder('red shoes')->options(['vector' => [0.4, 0.6]])->hybrid(1, 2);
        $params = $this->params($engine, $builder);

        $this->assertSame('red shoes', $params['q']);
        $this->assertSame('name', $params['query_by']);
        $this->assertSame('embedding:([0.4, 0.6], alpha: ' . (2 / 3) . ')', $params['vector_query']);
        $this->assertSame([], $engine->embedded);
    }

    public function test_semantic_search_with_native_embeddings_queries_the_embedding_field(): void
    {
        $params = $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->semantic());

        $this->assertSame('red shoes', $params['q']);
        $this->assertSame('embedding', $params['query_by']);
        $this->assertFalse($params['prefix']);
        $this->assertArrayNotHasKey('vector_query', $params);
        $this->assertSame('embedding', $params['exclude_fields']);
    }

    public function test_semantic_search_with_native_embeddings_applies_a_distance_threshold(): void
    {
        $params = $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->semantic(0.5));

        $this->assertSame('embedding:([], distance_threshold: 0.5)', $params['vector_query']);
    }

    public function test_hybrid_search_with_native_embeddings_appends_the_embedding_field(): void
    {
        $params = $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->hybrid());

        $this->assertSame('name,embedding', $params['query_by']);
        $this->assertSame('true,false', $params['prefix']);
        $this->assertSame('embedding:([], alpha: 0.5)', $params['vector_query']);
    }

    public function test_hybrid_search_with_native_embeddings_extends_per_field_lists(): void
    {
        $engine = $this->engine(self::NATIVE);
        $engine->setPrefix('true,false');
        $engine->setInfix('always,off');

        $params = $this->params($engine, $this->builder('red shoes', ['name', 'sku'])->hybrid());

        $this->assertSame('name,sku,embedding', $params['query_by']);
        $this->assertSame('true,false,false', $params['prefix']);
        $this->assertSame('always,off,off', $params['infix']);
    }

    public function test_existing_exclude_fields_are_kept(): void
    {
        $engine = $this->engine(self::NATIVE);
        $engine->setExcludeFields(['secret']);

        $params = $this->params($engine, $this->builder('red shoes')->semantic());

        $this->assertSame('secret,embedding', $params['exclude_fields']);
    }

    public function test_hybrid_search_requires_a_keyword_field(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');

        $this->params($this->engine(self::NATIVE), $this->builder('red shoes', ['embedding'])->hybrid());
    }

    public function test_semantic_search_cannot_be_combined_with_a_manual_vector_query(): void
    {
        $engine = $this->engine(self::NATIVE);
        $engine->nearestNeighbors('embedding', [0.1]);

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('cannot be combined with vectorQuery() or nearestNeighbors()');

        $this->params($engine, $this->builder('red shoes')->semantic());
    }

    public function test_semantic_search_requires_embedding_settings(): void
    {
        $engine = new TypesenseEngine($this->createStub(Typesense::class));

        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('No Typesense embedding settings have been configured for [' . SemanticModel::class . '].');

        $this->params($engine, $this->builder('red shoes')->semantic());
    }

    public function test_generated_embeddings_require_dimensions(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('Typesense embedding settings must contain positive [dimensions].');

        $this->params($this->engine(['attribute' => 'embedding']), $this->builder('red shoes')->semantic());
    }

    public function test_minimum_similarity_must_be_between_zero_and_one(): void
    {
        $this->expectException(ScoutException::class);
        $this->expectExceptionMessage('The minimum similarity must be between 0 and 1.');

        $this->params($this->engine(self::NATIVE), $this->builder('red shoes')->semantic(1.5));
    }

    public function test_model_method_settings_take_precedence_over_config(): void
    {
        $engine = $this->engine(['attribute' => 'from_config', 'driver' => 'typesense']);

        $builder = $this->builder('red shoes')->semantic();
        $builder->model->embeddingSettings = ['attribute' => 'from_model', 'driver' => 'typesense'];

        $this->assertSame('from_model', $this->params($engine, $builder)['query_by']);
    }

    public function test_update_adds_precomputed_and_generated_embeddings_to_documents(): void
    {
        $typesense = $this->createMock(Typesense::class);
        $typesense->method('getCollectionIndex')->willReturn($this->createStub(Collection::class));
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

        $this->assertSame([['abcd']], $engine->embedded);
    }

    public function test_update_does_not_generate_embeddings_with_native_driver(): void
    {
        $typesense = $this->createMock(Typesense::class);
        $typesense->method('getCollectionIndex')->willReturn($this->createStub(Collection::class));
        $typesense->expects($this->once())
            ->method('importDocuments')
            ->with($this->anything(), [['id' => 1, 'name' => 'abcd']]);

        $engine = $this->engine(self::NATIVE, [], $typesense);
        $engine->update(new EloquentCollection([SemanticModel::fake(1, 'abcd')]));

        $this->assertSame([], $engine->embedded);
    }
}

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
