<?php

namespace Siberfx\Typesense\Engines;

use Siberfx\Typesense\Engines\Filters\FilterBuilder;
use Siberfx\Typesense\Engines\Semantic\EmbeddingSettings;
use Siberfx\Typesense\Engines\Semantic\SemanticQueryBuilder;
use Siberfx\Typesense\Typesense;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\SupportsSemanticSearch;
use Laravel\Scout\Engines\Engine;
use Laravel\Scout\Exceptions\ScoutException;
use Illuminate\Support\Facades\Config;
use Typesense\Exceptions\ObjectAlreadyExists;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ObjectUnprocessable;
use Typesense\Exceptions\RequestMalformed;
use Typesense\Exceptions\RequestUnauthorized;
use Typesense\Exceptions\ServiceUnavailable;
use Typesense\Exceptions\TypesenseClientError;

/**
 * Class TypesenseEngine.
 *
 * @date    4/5/20
 *
 * @author  Selim Görmüş <info@siberfx.com>
 */
class TypesenseEngine extends Engine implements SupportsSemanticSearch
{
    /**
     * @var Typesense
     */
    private Typesense $typesense;

    /**
     * The `scout.typesense` configuration (used for `model-settings`).
     *
     * @var array
     */
    private array $config = [];

    /**
     * TypesenseEngine constructor.
     *
     * @param Typesense $typesense
     * @param array $config The `scout.typesense` configuration.
     */
    public function __construct(Typesense $typesense, array $config = [])
    {
        $this->typesense = $typesense;
        $this->config = $config;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection<int, Model>|Model[] $models
     *
     * @throws \Http\Client\Exception
     * @throws \JsonException
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @noinspection NotOptimalIfConditionsInspection
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        if ($this->usesSoftDelete($models->first())) {
            if (config('scout.soft_delete', false)) {
                $models->each->pushSoftDeleteMetadata();
            } else {
                // Trashed models must not be (re-)indexed when soft-deleted
                // records are excluded from the index; decide per model, not
                // from the first model of the batch.
                $models = $models->filter(static fn ($model) => is_null($model->getAttribute('deleted_at')))->values();
            }
        }

        $records = $models->map(static fn ($model) => ['model' => $model, 'document' => $model->toSearchableArray()])
            ->filter(static fn (array $record) => !empty($record['document']))
            ->values()
            ->all();

        if ($records === []) {
            return;
        }

        $settings = $this->embeddings()->has($models->first())
            ? $this->embeddings()->settings($models->first())
            : null;

        $documents = $settings !== null && !$this->embeddings()->usesNative($settings)
            ? $this->addEmbeddingsToDocuments($records, $settings)
            : array_column($records, 'document');

        $this->typesense->withCollection(
            $models->first(),
            fn ($collection) => $this->typesense->importDocuments($collection, $documents)
        );
    }

    private function filterBuilder(): FilterBuilder
    {
        return new FilterBuilder((bool) ($this->config['escape_filter_values'] ?? false));
    }

    private function embeddings(): EmbeddingSettings
    {
        return new EmbeddingSettings($this->config);
    }

    private function semanticQueries(): SemanticQueryBuilder
    {
        return new SemanticQueryBuilder(
            $this->embeddings(),
            fn (array $inputs, array $settings): array => $this->generateEmbeddings($inputs, $settings)
        );
    }

    protected function filters(Builder $builder): string
    {
        return $this->filterBuilder()->build($builder);
    }

    public function parseFilterValue(array|string|bool|int|float $value): array|string|bool|int|float
    {
        return $this->filterBuilder()->parseFilterValue($value);
    }

    public static function escapeFilterValue(string|int|float|bool $value): string
    {
        return FilterBuilder::escapeFilterValue($value);
    }

    public function parseWhereFilter(array|string $value, string $key, string $operator = '='): string
    {
        return $this->filterBuilder()->parseWhereFilter($value, $key, $operator);
    }

    public function parseWhereInFilter(array $value, string $key): string
    {
        return $this->filterBuilder()->parseWhereInFilter($value, $key);
    }

    public function parseWhereNotInFilter(array $value, string $key): string
    {
        return $this->filterBuilder()->parseWhereNotInFilter($value, $key);
    }

    protected function embeddingSettings($model): array
    {
        return $this->embeddings()->settings($model);
    }

    /**
     * Add generated (or precomputed) embeddings to the documents being indexed.
     *
     * Each model's toSearchableEmbedding() returns either the text to embed,
     * which is sent to the Laravel AI SDK in batches of 100, or a ready-made
     * embedding array, which is used as-is.
     *
     * @param array $records List of ['model' => Model, 'document' => array].
     * @param array $settings Validated embedding settings.
     *
     * @return array
     */
    protected function addEmbeddingsToDocuments(array $records, array $settings): array
    {
        $documents = [];

        foreach (array_chunk($records, 100) as $batch) {
            $inputs = [];
            $vectors = [];

            foreach ($batch as $index => $record) {
                if (!method_exists($record['model'], 'toSearchableEmbedding')) {
                    throw new ScoutException('Searchable models using generated embeddings must define a [toSearchableEmbedding] method.');
                }

                $input = $record['model']->toSearchableEmbedding();

                if (is_array($input)) {
                    $vectors[$index] = $input;

                    continue;
                }

                if (!is_string($input) || trim($input) === '') {
                    throw new ScoutException('The [toSearchableEmbedding] method must return a non-empty string or an embedding array.');
                }

                $inputs[$index] = $input;
            }

            if ($inputs !== []) {
                $generated = $this->generateEmbeddings(array_values($inputs), $settings);

                foreach (array_keys($inputs) as $position => $index) {
                    $vectors[$index] = $generated[$position];
                }
            }

            foreach ($batch as $index => $record) {
                $documents[] = array_merge($record['document'], [$settings['attribute'] => $vectors[$index]]);
            }
        }

        return $documents;
    }

    /**
     * @param \Illuminate\Database\Eloquent\Collection $models
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function delete($models): void
    {
        $models->each(function (Model $model) {
            // TODO look into this vs $model->getKey()
            $this->typesense->withCollection(
                $model,
                fn ($collectionIndex) => $this->typesense->deleteDocument($collectionIndex, $model->getScoutKey())
            );
        });
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     *
     * @return mixed
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function search(Builder $builder): mixed
    {
        return $this->performSearch($builder, $this->filterSearchParams($this->buildSearchParams($builder, 1, $builder->limit)));
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param int $perPage
     * @param int $page
     *
     * @return mixed
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function paginate(Builder $builder, $perPage, $page): mixed
    {
        return $this->performSearch($builder, $this->filterSearchParams($this->buildSearchParams($builder, $page, $perPage)));
    }

    /**
     * Drop unset (null/empty-string) parameters before sending the search.
     *
     * A bare array_filter() would also strip legitimate falsy values: boolean
     * options set to false (enable_overrides, prioritize_exact_match, ...)
     * would silently revert to the server defaults, and an empty `q` (a
     * filter-only search) would be dropped entirely even though Typesense
     * requires the parameter.
     *
     * @param array $params
     *
     * @return array
     */
    private function filterSearchParams(array $params): array
    {
        return array_filter(
            $params,
            static fn ($value, string $key): bool => $key === 'q' || ($value !== null && $value !== ''),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param int $page
     * @param int|null $perPage
     *
     * @return array
     */
    private function buildSearchParams(Builder $builder, int $page, int|null $perPage): array
    {
        $searchOptions = SearchOptions::for($builder);

        $params = [
            'q'                          => $builder->query,
            'query_by'                   => implode(',', $builder->model->typesenseQueryBy()),
            'filter_by'                  => $this->filters($builder),
            'per_page'                   => $perPage,
            'page'                       => $page,
            'highlight_start_tag'        => $searchOptions->startTag,
            'highlight_end_tag'          => $searchOptions->endTag,
            'snippet_threshold'          => $searchOptions->snippetThreshold,
            'exhaustive_search'          => $searchOptions->exhaustiveSearch,
            'use_cache'                  => $searchOptions->useCache,
            'cache_ttl'                  => $searchOptions->cacheTtl,
            'prioritize_exact_match'     => $searchOptions->prioritizeExactMatch,
            'enable_overrides'           => $searchOptions->enableOverrides,
            'highlight_affix_num_tokens' => $searchOptions->highlightAffixNumTokens,
            'infix'                      => $searchOptions->infix,
        ];

        if ($searchOptions->limitHits > 0) {
            $params['limit_hits'] = $searchOptions->limitHits;
        }

        if (!empty($searchOptions->groupBy)) {
            $params['group_by'] = implode(',', $searchOptions->groupBy);
            $params['group_limit'] = $searchOptions->groupByLimit;
        }

        if (!empty($searchOptions->facetBy)) {
            $params['facet_by'] = implode(',', $searchOptions->facetBy);
            $params['max_facet_values'] = $searchOptions->maxFacetValues;
        }

        if (!empty($searchOptions->facetQuery)) {
            $params['facet_query'] = $searchOptions->facetQuery;
        }

        if (!empty($searchOptions->includeFields)) {
            $params['include_fields'] = implode(',', $searchOptions->includeFields);
        }

        if (!empty($searchOptions->excludeFields)) {
            $params['exclude_fields'] = implode(',', $searchOptions->excludeFields);
        }

        if (!empty($searchOptions->highlightFields)) {
            $params['highlight_fields'] = implode(',', $searchOptions->highlightFields);
        }

        if (!empty($searchOptions->highlightFullFields)) {
            $params['highlight_full_fields'] = implode(',', $searchOptions->highlightFullFields);
        }

        if (!empty($searchOptions->pinnedHits)) {
            $params['pinned_hits'] = implode(',', $searchOptions->pinnedHits);
        }

        if (!empty($searchOptions->hiddenHits)) {
            $params['hidden_hits'] = implode(',', $searchOptions->hiddenHits);
        }

        if (!empty($searchOptions->locationOrderBy)) {
            $params['sort_by'] = $this->parseOrderByLocation(...$searchOptions->locationOrderBy);
        }

        if (!empty($builder->orders)) {
            if (!empty($params['sort_by'])) {
                $params['sort_by'] .= ',';
            } else {
                $params['sort_by'] = '';
            }
            $params['sort_by'] .= $this->parseOrderBy($builder->orders);
        }
        
        if (!empty($searchOptions->prefix)) {
            $params['prefix'] = $searchOptions->prefix;
        }

        if (!empty($searchOptions->vectorQuery)) {
            $params['vector_query'] = $searchOptions->vectorQuery;
        }

        if (!empty($searchOptions->synonymSets)) {
            $params['synonym_sets'] = implode(',', $searchOptions->synonymSets);
        }

        if (!empty($searchOptions->curationTags)) {
            $params['curation_tags'] = implode(',', $searchOptions->curationTags);
        }

        if ($searchOptions->diversityLambda !== null) {
            $params['diversity_lambda'] = $searchOptions->diversityLambda;
        }

        if ($searchOptions->groupMaxCandidates !== null) {
            $params['group_max_candidates'] = $searchOptions->groupMaxCandidates;
        }

        // Scout's options() passes any other Typesense search parameter
        // through; `vector` is Scout's precomputed embedding, not a parameter.
        $params = array_merge($params, array_diff_key($builder->options, ['vector' => true]));

        return $this->semanticQueries()->apply($builder, $params, $searchOptions);
    }

    /**
     * Generate embeddings through the optional Laravel AI SDK (laravel/ai).
     *
     * @param array $inputs
     * @param array $settings
     *
     * @return array
     */
    protected function generateEmbeddings(array $inputs, array $settings): array
    {
        $embeddingsClass = 'Laravel\\Ai\\Embeddings';

        if (!class_exists($embeddingsClass)) {
            throw new ScoutException('Semantic search requires the Laravel AI SDK. Please install the [laravel/ai] package.');
        }

        $response = $embeddingsClass::for(array_values($inputs))
            ->dimensions($settings['dimensions'])
            ->cache()
            ->generate($settings['provider'] ?? null, $settings['model'] ?? null);

        $embeddings = $response->embeddings;

        if (!is_array($embeddings) || count($embeddings) !== count($inputs)) {
            throw new ScoutException('Laravel AI returned an unexpected number of embeddings.');
        }

        return $embeddings;
    }

    /**
     * Parse location order by for sort_by.
     *
     * @param string $column
     * @param float $lat
     * @param float $lng
     * @param string $direction
     *
     * @return string
     * @noinspection PhpPureAttributeCanBeAddedInspection
     */
    private function parseOrderByLocation(string $column, float $lat, float $lng, string $direction = 'asc'): string
    {
        $direction = Str::lower($direction) === 'asc' ? 'asc' : 'desc';
        $str = $column . '(' . $lat . ', ' . $lng . ')';

        return $str . ':' . $direction;
    }

    /**
     * Parse sort_by fields.
     *
     * @param array $orders
     *
     * @return string
     */
    private function parseOrderBy(array $orders): string
    {
        $sortByArr = [];
        foreach ($orders as $order) {
            $sortByArr[] = $order['column'] . ':' . $order['direction'];
        }

        return implode(',', $sortByArr);
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param array $options
     *
     * @return mixed
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    protected function performSearch(Builder $builder, array $options = []): mixed
    {
        $searchOptions = SearchOptions::for($builder);

        if ($builder->callback) {
            $documents = $this->typesense->withCollection(
                $builder->model,
                static fn ($collection) => $collection->getDocuments()
            );

            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        if ($searchOptions->optionsMulti) {
            return $this->typesense->multiSearch(...$this->moveVectorQueryIntoSearches($searchOptions->optionsMulti, $options));
        }

        if (!isset($options['vector_query'])) {
            return $this->typesense->withCollection(
                $builder->model,
                static fn ($collection) => $collection->getDocuments()->search($options)
            );
        }

        // Vector queries go through multi-search, which needs no handle, but the
        // collection is still ensured to exist, as a plain search does.
        $this->typesense->withCollection($builder->model, static fn ($collection) => $collection);

        // A serialized embedding easily exceeds Typesense's query string
        // length limit on a GET search, so vector queries are sent in the
        // POST body of a single-entry multi-search instead.
        $results = $this->typesense->multiSearch([
            'searches' => [
                array_merge($options, ['collection' => $builder->model->searchableAs()]),
            ],
        ], []);

        $result = $results['results'][0] ?? [];

        if (isset($result['error'])) {
            throw $this->marshalMultiSearchException($result);
        }

        return $result;
    }

    /**
     * Move a common `vector_query` out of the multi-search query string and
     * into each search body (unless a search defines its own), for the same
     * query-string length reason as single vector searches.
     *
     * @param array $searches
     * @param array $commonParams
     *
     * @return array{0: array, 1: array}
     */
    private function moveVectorQueryIntoSearches(array $searches, array $commonParams): array
    {
        if (isset($commonParams['vector_query'])) {
            $searches = array_map(
                static fn (array $search): array => $search + ['vector_query' => $commonParams['vector_query']],
                $searches
            );

            unset($commonParams['vector_query']);
        }

        return [['searches' => $searches], $commonParams];
    }

    /**
     * Convert a multi-search error entry into the matching Typesense exception.
     *
     * @param array $result
     *
     * @return \Typesense\Exceptions\TypesenseClientError
     */
    private function marshalMultiSearchException(array $result): TypesenseClientError
    {
        $exception = match ((int) ($result['code'] ?? 500)) {
            400 => new RequestMalformed(),
            401 => new RequestUnauthorized(),
            404 => new ObjectNotFound(),
            409 => new ObjectAlreadyExists(),
            422 => new ObjectUnprocessable(),
            503 => new ServiceUnavailable(),
            default => new TypesenseClientError(),
        };

        return $exception->setMessage((string) $result['error']);
    }

    /**
     * @param mixed $results
     *
     * @return \Illuminate\Support\Collection
     */
    public function mapIds($results): Collection
    {
        return collect($this->extractHitIds($results));
    }

    /**
     * Extract the matched document ids from a search response, transparently
     * handling grouped (`group_by`) responses, whose hits live under
     * `grouped_hits` instead of `hits`.
     *
     * @param mixed $results
     *
     * @return array
     */
    private function extractHitIds($results): array
    {
        $grouped = !empty($results['grouped_hits'] ?? null);

        return collect($grouped ? $results['grouped_hits'] : ($results['hits'] ?? []))
            ->pluck($grouped ? 'hits.0.document.id' : 'document.id')
            ->values()
            ->all();
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function map(Builder $builder, $results, $model): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->getTotalCount($results) === 0) {
            return $model->newCollection();
        }

        $objectIds = $this->extractHitIds($results);

        $objectIdPositions = array_flip($objectIds);

        return $model->getScoutModelsByIds($builder, $objectIds)
            ->filter(static function ($model) use ($objectIdPositions) {
                return isset($objectIdPositions[$model->getScoutKey()]);
            })
            ->sortBy(static function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })
            ->values();
    }

    /**
     * @inheritDoc
     */
    public function getTotalCount($results): int
    {
        return (int)($results['found'] ?? 0);
    }

    /**
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @throws \Http\Client\Exception
     * @throws \Typesense\Exceptions\TypesenseClientError
     */
    public function flush($model): void
    {
        $collection = $this->typesense->getCollectionIndex($model);
        $collection->delete();
        $this->typesense->forgetCollection($model->searchableAs());
    }

    /**
     * @param $model
     *
     * @return bool
     */
    protected function usesSoftDelete($model): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true);
    }

    /**
     * @param \Laravel\Scout\Builder $builder
     * @param mixed $results
     * @param \Illuminate\Database\Eloquent\Model $model
     *
     * @return \Illuminate\Support\LazyCollection
     */
    public function lazyMap(Builder $builder, $results, $model): LazyCollection
    {
        if ($this->getTotalCount($results) === 0) {
            return LazyCollection::make($model->newCollection());
        }

        $objectIds = $this->extractHitIds($results);

        $objectIdPositions = array_flip($objectIds);

        return $model->queryScoutModelsByIds($builder, $objectIds)
            ->cursor()
            ->filter(static function ($model) use ($objectIdPositions) {
                return isset($objectIdPositions[$model->getScoutKey()]);
            })
            ->sortBy(static function ($model) use ($objectIdPositions) {
                return $objectIdPositions[$model->getScoutKey()];
            })
            ->values();
    }

    /**
     * @param string $name
     * @param array $options
     *
     * @return void
     * @throws \Exception
     *
     */
    public function createIndex($name, array $options = []): void
    {
        throw new Exception('Typesense indexes are created automatically upon adding objects.');
    }

    /**
     * @param string $name
     *
     * @return array
     * @throws \Typesense\Exceptions\TypesenseClientError
     * @throws \Http\Client\Exception
     *
     * @throws \Typesense\Exceptions\ObjectNotFound
     */
    public function deleteIndex($name): array
    {
        return $this->typesense->deleteCollection($name);
    }
}
