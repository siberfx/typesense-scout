<?php

namespace Siberfx\Typesense\Engines;

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
     * @var array
     */
    private array $groupBy = [];

    /**
     * @var int
     */
    private int $groupByLimit = 3;

    /**
     * @var string
     */
    private string $startTag = '<mark>';

    /**
     * @var string
     */
    private string $endTag = '</mark>';

    /**
     * @var int
     */
    private int $limitHits = -1;

    /**
     * @var array
     */
    private array $locationOrderBy = [];

    /**
     * @var array
     */
    private array $facetBy = [];

    /**
     * @var int
     */
    private int $maxFacetValues = 10;

    /**
     * @var bool
     */
    private bool $useCache = false;

    /**
     * @var int
     */
    private int $cacheTtl = 60;

    /**
     * @var int
     */
    private int $snippetThreshold = 30;

    /**
     * @var bool
     */
    private bool $exhaustiveSearch = false;

    /**
     * @var bool
     */
    private bool $prioritizeExactMatch = true;

    /**
     * @var bool
     */
    private bool $enableOverrides = true;

    /**
     * @var int
     */
    private int $highlightAffixNumTokens = 4;

    /**
     * @var string
     */
    private string $facetQuery = '';

    /**
     * @var string
     */
    private string $infix = 'off';

    /**
     * @var array
     */
    private array $includeFields = [];

    /**
     * @var array
     */
    private array $excludeFields = [];

    /**
     * @var array
     */
    private array $highlightFields = [];

    /**
     * @var array
     */
    private array $highlightFullFields = [];

    /**
     * @var array
     */
    private array $pinnedHits = [];

    /**
     * @var array
     */
    private array $hiddenHits = [];

    /**
     * @var array
     */
    private array $optionsMulti = [];

    /**
     * @var string|null
     */
    private ?string $prefix = null;

    /**
     * @var string
     */
    private string $vectorQuery = '';

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

        $collection = $this->typesense->getCollectionIndex($models->first());

        if ($this->usesSoftDelete($models->first())) {
            if (config('scout.soft_delete', false)) {
                $models->each->pushSoftDeleteMetadata();
            } else {
                // Trashed models must not be (re-)indexed when soft-deleted
                // records are excluded from the index; decide per model, not
                // from the first model of the batch.
                $models = $models->filter(static fn ($model) => is_null($model->deleted_at))->values();
            }
        }

        $records = $models->map(static fn ($model) => ['model' => $model, 'document' => $model->toSearchableArray()])
            ->filter(static fn (array $record) => !empty($record['document']))
            ->values()
            ->all();

        if ($records === []) {
            return;
        }

        $settings = $this->hasEmbeddingSettings($models->first())
            ? $this->embeddingSettings($models->first())
            : null;

        $documents = $settings !== null && !$this->usesNativeEmbeddings($settings)
            ? $this->addEmbeddingsToDocuments($records, $settings)
            : array_column($records, 'document');

        $this->typesense->importDocuments($collection, $documents);
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
            $collectionIndex = $this->typesense->getCollectionIndex($model);

            // TODO look into this vs $model->getKey()
            $this->typesense->deleteDocument($collectionIndex, $model->getScoutKey());
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
        try {
            return $this->performSearch($builder, $this->filterSearchParams($this->buildSearchParams($builder, 1, $builder->limit)));
        } finally {
            $this->resetSearchParameters();
        }
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
        try {
            return $this->performSearch($builder, $this->filterSearchParams($this->buildSearchParams($builder, $page, $perPage)));
        } finally {
            $this->resetSearchParameters();
        }
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
     * Restore the per-query fluent options to their defaults.
     *
     * The engine is resolved once per container, so options set through the
     * Builder mixin (groupBy, facetBy, vector queries, multi-search, ...)
     * would otherwise leak into every subsequent search — including searches
     * from other requests in long-lived workers (queues, Octane).
     */
    private function resetSearchParameters(): void
    {
        $this->groupBy = [];
        $this->groupByLimit = 3;
        $this->startTag = '<mark>';
        $this->endTag = '</mark>';
        $this->limitHits = -1;
        $this->locationOrderBy = [];
        $this->facetBy = [];
        $this->maxFacetValues = 10;
        $this->useCache = false;
        $this->cacheTtl = 60;
        $this->snippetThreshold = 30;
        $this->exhaustiveSearch = false;
        $this->prioritizeExactMatch = true;
        $this->enableOverrides = true;
        $this->highlightAffixNumTokens = 4;
        $this->facetQuery = '';
        $this->infix = 'off';
        $this->includeFields = [];
        $this->excludeFields = [];
        $this->highlightFields = [];
        $this->highlightFullFields = [];
        $this->pinnedHits = [];
        $this->hiddenHits = [];
        $this->optionsMulti = [];
        $this->prefix = null;
        $this->vectorQuery = '';
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
        $params = [
            'q'                          => $builder->query,
            'query_by'                   => implode(',', $builder->model->typesenseQueryBy()),
            'filter_by'                  => $this->filters($builder),
            'per_page'                   => $perPage,
            'page'                       => $page,
            'highlight_start_tag'        => $this->startTag,
            'highlight_end_tag'          => $this->endTag,
            'snippet_threshold'          => $this->snippetThreshold,
            'exhaustive_search'          => $this->exhaustiveSearch,
            'use_cache'                  => $this->useCache,
            'cache_ttl'                  => $this->cacheTtl,
            'prioritize_exact_match'     => $this->prioritizeExactMatch,
            'enable_overrides'           => $this->enableOverrides,
            'highlight_affix_num_tokens' => $this->highlightAffixNumTokens,
            'infix'                      => $this->infix,
        ];

        if ($this->limitHits > 0) {
            $params['limit_hits'] = $this->limitHits;
        }

        if (!empty($this->groupBy)) {
            $params['group_by'] = implode(',', $this->groupBy);
            $params['group_limit'] = $this->groupByLimit;
        }

        if (!empty($this->facetBy)) {
            $params['facet_by'] = implode(',', $this->facetBy);
            $params['max_facet_values'] = $this->maxFacetValues;
        }

        if (!empty($this->facetQuery)) {
            $params['facet_query'] = $this->facetQuery;
        }

        if (!empty($this->includeFields)) {
            $params['include_fields'] = implode(',', $this->includeFields);
        }

        if (!empty($this->excludeFields)) {
            $params['exclude_fields'] = implode(',', $this->excludeFields);
        }

        if (!empty($this->highlightFields)) {
            $params['highlight_fields'] = implode(',', $this->highlightFields);
        }

        if (!empty($this->highlightFullFields)) {
            $params['highlight_full_fields'] = implode(',', $this->highlightFullFields);
        }

        if (!empty($this->pinnedHits)) {
            $params['pinned_hits'] = implode(',', $this->pinnedHits);
        }

        if (!empty($this->hiddenHits)) {
            $params['hidden_hits'] = implode(',', $this->hiddenHits);
        }

        if (!empty($this->locationOrderBy)) {
            $params['sort_by'] = $this->parseOrderByLocation(...$this->locationOrderBy);
        }

        if (!empty($builder->orders)) {
            if (!empty($params['sort_by'])) {
                $params['sort_by'] .= ',';
            } else {
                $params['sort_by'] = '';
            }
            $params['sort_by'] .= $this->parseOrderBy($builder->orders);
        }
        
        if (!empty($this->prefix)) {
            $params['prefix'] = $this->prefix;
        }

        if (!empty($this->vectorQuery)) {
            $params['vector_query'] = $this->vectorQuery;
        }

        return $this->applySemanticSearchParams($builder, $params);
    }

    /**
     * Apply Scout's semantic() / hybrid() builder state to the search params.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $params
     *
     * @return array
     */
    private function applySemanticSearchParams(Builder $builder, array $params): array
    {
        if (!$builder->semanticSearch && $builder->hybridSearch === null) {
            return $params;
        }

        if ($this->vectorQuery !== '') {
            throw new ScoutException('Typesense semantic and hybrid searches cannot be combined with vectorQuery() or nearestNeighbors().');
        }

        $settings = $this->embeddingSettings($builder->model);

        if ($builder->semanticSearch) {
            $params = $this->usesNativeEmbeddings($settings)
                ? $this->applyNativeSemanticQueryBy($params, $settings['attribute'])
                : array_merge($params, ['q' => '*']);
        } else {
            $params = $this->applyHybridQueryBy($params, $settings);
        }

        if (($vectorQuery = $this->buildSemanticVectorQuery($builder, $settings)) !== null) {
            $params['vector_query'] = $vectorQuery;
        }

        // Never ship the (large) embedding back with every hit.
        $params['exclude_fields'] = $this->appendField($params['exclude_fields'] ?? '', $settings['attribute']);

        return $params;
    }

    /**
     * Point a native-embedding semantic search at the embedding field only.
     *
     * @param array $params
     * @param string $attribute
     *
     * @return array
     */
    private function applyNativeSemanticQueryBy(array $params, string $attribute): array
    {
        $params['query_by'] = $attribute;

        // Remote embedders reject prefix searches on embedding fields.
        $params['prefix'] = false;

        // Per-field lists no longer line up with the single query_by field.
        if (isset($params['infix']) && str_contains((string) $params['infix'], ',')) {
            unset($params['infix']);
        }

        return $params;
    }

    /**
     * Prepare query_by (and its per-field params) for a hybrid search.
     *
     * @param array $params
     * @param array $settings
     *
     * @return array
     */
    private function applyHybridQueryBy(array $params, array $settings): array
    {
        $fields = array_filter(array_map('trim', explode(',', (string) ($params['query_by'] ?? ''))));

        if (array_diff($fields, [$settings['attribute']]) === []) {
            throw new ScoutException('Typesense hybrid searches require at least one keyword field in the [query_by] search parameter.');
        }

        if (!$this->usesNativeEmbeddings($settings) || in_array($settings['attribute'], $fields, true)) {
            return $params;
        }

        $params['query_by'] = implode(',', [...$fields, $settings['attribute']]);

        if (isset($params['infix']) && str_contains((string) $params['infix'], ',')) {
            $params['infix'] .= ',off';
        }

        // Prefix search defaults to true, which remote embedders reject on the
        // embedding field, so it has to become a per-field list.
        $prefix = $params['prefix'] ?? true;

        if (is_string($prefix) && str_contains($prefix, ',')) {
            $params['prefix'] = $prefix . ',false';
        } elseif ($prefix === true || $prefix === 'true') {
            $params['prefix'] = implode(',', [...array_fill(0, count($fields), 'true'), 'false']);
        }

        return $params;
    }

    /**
     * Build the vector_query for a semantic / hybrid search.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $settings
     *
     * @return string|null
     */
    private function buildSemanticVectorQuery(Builder $builder, array $settings): ?string
    {
        if ($this->usesNativeEmbeddings($settings)) {
            $vector = [];
        } else {
            $vector = $builder->options['vector'] ?? $this->generateEmbeddings([$builder->query], $settings)[0];

            if (!is_array($vector) || $vector === []) {
                throw new ScoutException('The Typesense query [vector] must be a non-empty embedding array.');
            }
        }

        $options = [];

        if ($builder->hybridSearch !== null) {
            $options[] = 'alpha: ' . ($builder->hybridSearch['semantic_weight'] / array_sum($builder->hybridSearch));
        }

        if ($builder->minimumSimilarity !== null) {
            $options[] = 'distance_threshold: ' . $this->distanceThreshold($builder->minimumSimilarity);
        }

        if ($vector === [] && $options === []) {
            return null;
        }

        return sprintf(
            '%s:([%s]%s)',
            $settings['attribute'],
            implode(', ', $vector),
            $options === [] ? '' : ', ' . implode(', ', $options)
        );
    }

    /**
     * Convert a minimum cosine similarity (0..1) into a max vector distance.
     *
     * @param mixed $similarity
     *
     * @return float
     */
    private function distanceThreshold(mixed $similarity): float
    {
        if (!is_numeric($similarity) || $similarity < 0 || $similarity > 1) {
            throw new ScoutException('The minimum similarity must be between 0 and 1.');
        }

        return 1 - $similarity;
    }

    /**
     * Append a field to a comma-separated field list unless already present.
     *
     * @param string $fields
     * @param string $field
     *
     * @return string
     */
    private function appendField(string $fields, string $field): string
    {
        $fields = array_filter(array_map('trim', explode(',', $fields)));

        if (!in_array($field, $fields, true)) {
            $fields[] = $field;
        }

        return implode(',', $fields);
    }

    /**
     * Get the raw embedding settings for a model.
     *
     * A model-level typesenseEmbeddingSettings() method takes precedence over
     * `scout.typesense.model-settings.{Model}.embedding` (Scout's format).
     *
     * @param mixed $model
     *
     * @return mixed
     */
    private function rawEmbeddingSettings($model): mixed
    {
        if (method_exists($model, 'typesenseEmbeddingSettings')
            && ($settings = $model->typesenseEmbeddingSettings()) !== null) {
            return $settings;
        }

        return $this->config['model-settings'][get_class($model)]['embedding'] ?? null;
    }

    /**
     * @param mixed $model
     *
     * @return bool
     */
    private function hasEmbeddingSettings($model): bool
    {
        return !empty($this->rawEmbeddingSettings($model));
    }

    /**
     * Get the validated embedding settings for a model.
     *
     * @param mixed $model
     *
     * @return array
     */
    protected function embeddingSettings($model): array
    {
        $settings = $this->rawEmbeddingSettings($model);

        if (!is_array($settings) || $settings === []) {
            throw new ScoutException('No Typesense embedding settings have been configured for [' . get_class($model) . '].');
        }

        if (!isset($settings['attribute']) || !is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ScoutException('Typesense embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'laravel-ai';

        if (!in_array($driver, ['laravel-ai', 'typesense'], true)) {
            throw new ScoutException("The [{$driver}] Typesense embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNativeEmbeddings($settings)) {
            return $settings;
        }

        if (!isset($settings['dimensions'])
            || filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false
            || $settings['dimensions'] < 1) {
            throw new ScoutException('Typesense embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
    }

    /**
     * Whether Typesense generates the embeddings itself (an `embed` field).
     *
     * @param array $settings
     *
     * @return bool
     */
    private function usesNativeEmbeddings(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'typesense';
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
        if ($builder->callback) {
            $documents = $this->typesense->getCollectionIndex($builder->model)
                ->getDocuments();

            return call_user_func($builder->callback, $documents, $builder->query, $options);
        }

        if ($this->optionsMulti) {
            return $this->typesense->multiSearch(...$this->moveVectorQueryIntoSearches($this->optionsMulti, $options));
        }

        $documents = $this->typesense->getCollectionIndex($builder->model)->getDocuments();

        if (!isset($options['vector_query'])) {
            return $documents->search($options);
        }

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
     * Prepare filters.
     *
     * @param Builder $builder
     *
     * @return string
     */
    protected function filters(Builder $builder): string
    {
        $escape = (bool) ($this->config['escape_filter_values'] ?? false);

        // Operator arrays such as ['>', 100] are filter syntax, never escaped.
        $whereValue = fn ($value) => $escape && !is_array($value)
            ? $this->escapeWhereValue($value)
            : $this->parseFilterValue($value);

        $listValue = fn (array $values) => $escape
            ? array_map([$this, 'escapeWhereValue'], $values)
            : $this->parseFilterValue($values);

        $whereFilter = collect($builder->wheres)
            ->map(fn ($value, $key) => $this->parseWhereFilter($whereValue($value), $key))
            ->values()
            ->implode(' && ');

        $whereInFilter = collect($builder->whereIns)
            ->map(fn ($value, $key) => $this->parseWhereInFilter($listValue($value), $key))
            ->values()
            ->implode(' && ');

        $whereNotInFilter = collect($builder->whereNotIns)
            ->map(fn ($value, $key) => $this->parseWhereNotInFilter($listValue($value), $key))
            ->values()
            ->implode(' && ');

        return collect([$whereFilter, $whereInFilter, $whereNotInFilter])
            ->filter(static fn (string $filter): bool => $filter !== '')
            ->implode(' && ');
    }

    /**
     * Normalise a filter value before it is rendered into a Typesense filter
     * string. Booleans become Typesense's literal `true`/`false`, and arrays
     * are normalised recursively (so operator arrays such as ['>', 100] and
     * whereIn/whereNotIn value lists are handled consistently).
     *
     * @param array|string|bool|int|float $value
     *
     * @return array|string|bool|int|float
     */
    public function parseFilterValue(array|string|bool|int|float $value): array|string|bool|int|float
    {
        if (is_array($value)) {
            return array_map([$this, 'parseFilterValue'], $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * Escape a value for safe use in a Typesense `filter_by` string.
     *
     * Strings are wrapped in backticks (inner backticks escaped), so values
     * containing `&&`, `||`, `,`, `[`, `]` or `:` cannot alter the filter.
     * Same behaviour as \Typesense\FilterBy::escape() in typesense-php 6.1.
     *
     * @param string|int|float|bool $value
     *
     * @return string
     */
    public static function escapeFilterValue(string|int|float|bool $value): string
    {
        return match (true) {
            is_string($value) => '`' . str_replace('`', '\\`', $value) . '`',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Escape a where/whereIn value when `scout.typesense.escape_filter_values`
     * is enabled. Numeric strings (typical of request input) stay unquoted so
     * they keep matching numeric fields.
     *
     * @param string|int|float|bool $value
     *
     * @return string
     */
    private function escapeWhereValue(string|int|float|bool $value): string
    {
        return is_string($value) && is_numeric($value) ? $value : static::escapeFilterValue($value);
    }

    /**
     * Parse typesense where filter.
     *
     * Passing an array enables comparison/range operators, e.g.
     * where('price', ['>', 100]) => "price:>100" and
     * where('price', ['[10..100]']) => "price:[10..100]".
     *
     * @param array|string $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereFilter(array|string $value, string $key): string
    {
        if (is_array($value)) {
            return sprintf('%s:%s', $key, implode('', $value));
        }

        return sprintf('%s:=%s', $key, $value);
    }

    /**
     * Parse typesense  whereIn filter.
     *
     * @param array $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereInFilter(array $value, string $key): string
    {
        return sprintf('%s:=%s', $key, '[' . implode(', ', $value) . ']');
    }

    /**
     * Parse typesense whereNotIn filter.
     *
     * @param array $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereNotInFilter(array $value, string $key): string
    {
        return sprintf('%s:!=%s', $key, '[' . implode(', ', $value) . ']');
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
            ->filter(static function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
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
            ->filter(static function ($model) use ($objectIds) {
                return in_array($model->getScoutKey(), $objectIds, false);
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
     * You can aggregate search results into groups or buckets by specify one or more group_by fields. Separate multiple fields with a comma.
     *
     * @param mixed $groupBy
     *
     * @return $this
     */
    public function groupBy(array $groupBy): static
    {
        $this->groupBy = $groupBy;

        return $this;
    }

    /**
     * Maximum number of hits to be returned for every group. (default: 3).
     *
     * @param int $groupByLimit
     *
     * @return $this
     */
    public function groupByLimit(int $groupByLimit): static
    {
        $this->groupByLimit = $groupByLimit;

        return $this;
    }

    /**
     * The start tag used for the highlighted snippets. (default: <mark>).
     *
     * @param string $startTag
     *
     * @return $this
     */
    public function setHighlightStartTag(string $startTag): static
    {
        $this->startTag = $startTag;

        return $this;
    }

    /**
     * The end tag used for the highlighted snippets. (default: </mark>).
     *
     * @param string $endTag
     *
     * @return $this
     */
    public function setHighlightEndTag(string $endTag): static
    {
        $this->endTag = $endTag;

        return $this;
    }

    /**
     * Maximum number of hits that can be fetched from the collection (default: no limit).
     *
     * (page * per_page) should be less than this number for the search request to return results.
     *
     * @param int $limitHits
     *
     * @return $this
     */
    public function limitHits(int $limitHits): static
    {
        $this->limitHits = $limitHits;

        return $this;
    }

    /**
     * A list of fields that will be used for faceting your results on. Separate multiple fields with a comma.
     *
     * @param mixed $facetBy
     *
     * @return $this
     */
    public function facetBy(array $facetBy): static
    {
        $this->facetBy = $facetBy;

        return $this;
    }

    /**
     * Maximum number of facet values to be returned.
     *
     * @param int $maxFacetValues
     *
     * @return $this
     */
    public function setMaxFacetValues(int $maxFacetValues): static
    {
        $this->maxFacetValues = $maxFacetValues;

        return $this;
    }

    /**
     * Facet values that are returned can now be filtered via this parameter.
     *
     * The matching facet text is also highlighted. For example, when faceting by category,
     * you can set facet_query=category:shoe to return only facet values that contain the prefix "shoe".
     *
     * @param string $facetQuery
     *
     * @return $this
     */
    public function facetQuery(string $facetQuery): static
    {
        $this->facetQuery = $facetQuery;

        return $this;
    }

    /**
     * Comma-separated list of fields from the document to include in the search result.
     *
     * @param mixed $includeFields
     *
     * @return $this
     */
    public function setIncludeFields(array $includeFields): static
    {
        $this->includeFields = $includeFields;

        return $this;
    }

    /**
     * Comma-separated list of fields from the document to exclude in the search result.
     *
     * @param mixed $excludeFields
     *
     * @return $this
     */
    public function setExcludeFields(array $excludeFields): static
    {
        $this->excludeFields = $excludeFields;

        return $this;
    }

    /**
     * Comma separated list of fields that should be highlighted with snippetting.
     *
     * You can use this parameter to highlight fields that you don't query for, as well.
     *
     * @param mixed $highlightFields
     *
     * @return $this
     */
    public function setHighlightFields(array $highlightFields): static
    {
        $this->highlightFields = $highlightFields;

        return $this;
    }

    /**
     * A list of records to unconditionally include in the search results at specific positions.
     *
     * @param mixed $pinnedHits
     *
     * @return $this
     */
    public function setPinnedHits(array $pinnedHits): static
    {
        $this->pinnedHits = $pinnedHits;

        return $this;
    }

    /**
     * A list of records to unconditionally hide from search results.
     *
     * @param mixed $hiddenHits
     *
     * @return $this
     */
    public function setHiddenHits(array $hiddenHits): static
    {
        $this->hiddenHits = $hiddenHits;

        return $this;
    }

    /**
     * Comma separated list of fields which should be highlighted fully without snippeting.
     *
     * @param mixed $highlightFullFields
     *
     * @return $this
     */
    public function setHighlightFullFields(array $highlightFullFields): static
    {
        $this->highlightFullFields = $highlightFullFields;

        return $this;
    }

    /**
     * The number of tokens that should surround the highlighted text on each side.
     *
     * This controls the length of the snippet.
     *
     * @param int $highlightAffixNumTokens
     *
     * @return $this
     */
    public function setHighlightAffixNumTokens(int $highlightAffixNumTokens): static
    {
        $this->highlightAffixNumTokens = $highlightAffixNumTokens;

        return $this;
    }

    /**
     * Set the infix search option for the field.
     *
     * @param string $infix The infix search option to enable for the field.
     *                      Possible values: "off" (disabled, default), "always" (along with regular search),
     *                      "fallback" (if regular search produces no results).
     * @return $this
     */
    public function setInfix(string $infix): static
    {
        $this->infix = $infix;

        return $this;
    }

    /**
     * Field values under this length will be fully highlighted, instead of showing a snippet of relevant portion.
     *
     * @param int $snippetThreshold
     *
     * @return $this
     */
    public function setSnippetThreshold(int $snippetThreshold): static
    {
        $this->snippetThreshold = $snippetThreshold;

        return $this;
    }

    /**
     * Setting this to true will make Typesense consider all variations of prefixes and typo corrections of the words
     *
     * in the query exhaustively, without stopping early when enough results are found.
     *
     * @param bool $exhaustiveSearch
     *
     * @return $this
     */
    public function exhaustiveSearch(bool $exhaustiveSearch): static
    {
        $this->exhaustiveSearch = $exhaustiveSearch;

        return $this;
    }

    /**
     * Enable server side caching of search query results. By default, caching is disabled.
     *
     * @param bool $useCache
     *
     * @return $this
     */
    public function setUseCache(bool $useCache): static
    {
        $this->useCache = $useCache;

        return $this;
    }

    /**
     * The duration (in seconds) that determines how long the search query is cached.
     *
     * @param int $cacheTtl
     *
     * @return $this
     */
    public function setCacheTtl(int $cacheTtl): static
    {
        $this->cacheTtl = $cacheTtl;

        return $this;
    }

    /**
     * By default, Typesense prioritizes documents whose field value matches exactly with the query.
     *
     * @param bool $prioritizeExactMatch
     *
     * @return $this
     */
    public function setPrioritizeExactMatch(bool $prioritizeExactMatch): static
    {
        $this->prioritizeExactMatch = $prioritizeExactMatch;

        return $this;
    }

    /**
     * Indicates that the last word in the query should be treated as a prefix, and not as a whole word. 
     * 
     * You can also control the behavior of prefix search on a per field basis.
     * For example, if you are querying 3 fields and want to enable prefix searching only on the first field, use ?prefix=true,false,false. 
     * The order should match the order of fields in query_by. 
     * If a single value is specified for prefix the same value is used for all fields specified in query_by.
     *
     * @param string $prefix
     *
     * @return $this
     */
    public function setPrefix(string $prefix): static
    {
        $this->prefix = $prefix;

        return $this;
    }

    /**
     * Set a raw Typesense `vector_query` string for semantic / hybrid search.
     *
     * Use this for full control, e.g.
     * "embedding:([0.1, 0.2, 0.3], k:10, distance_threshold:0.3, alpha:0.4)".
     *
     * For a pure vector search set the query to "*" (e.g. Model::search('*')).
     * Providing both a text query and a vector_query performs a hybrid search.
     *
     * @param string $vectorQuery
     *
     * @return $this
     */
    public function vectorQuery(string $vectorQuery): static
    {
        $this->vectorQuery = $vectorQuery;

        return $this;
    }

    /**
     * Build a `vector_query` for nearest-neighbour / hybrid search against a
     * vector field, without hand-writing the Typesense syntax.
     *
     * @param string     $field             The vector field to search against.
     * @param array      $vector            The query embedding (array of floats).
     * @param int        $k                 Number of nearest neighbours to return.
     * @param float|null $distanceThreshold Optional max vector distance.
     * @param float|null $alpha             Optional hybrid weight (0..1) between
     *                                      keyword and vector ranking.
     *
     * @return $this
     */
    public function nearestNeighbors(string $field, array $vector, int $k = 10, ?float $distanceThreshold = null, ?float $alpha = null): static
    {
        $options = ['k:' . $k];

        if ($distanceThreshold !== null) {
            $options[] = 'distance_threshold:' . $distanceThreshold;
        }

        if ($alpha !== null) {
            $options[] = 'alpha:' . $alpha;
        }

        $this->vectorQuery = sprintf('%s:([%s], %s)', $field, implode(', ', $vector), implode(', ', $options));

        return $this;
    }

    /**
     * If you have some overrides defined but want to disable all of them for a particular search query
     *
     * @param bool $enableOverrides
     *
     * @return $this
     */
    public function enableOverrides(bool $enableOverrides): static
    {
        $this->enableOverrides = $enableOverrides;

        return $this;
    }

    /**
     * If you want to search multi queries in the same call
     *
     * @param array $optionsMulti
     *
     * @return $this
     */
    public function searchMulti(array $optionsMulti): static
    {
        $this->optionsMulti = $optionsMulti;

        return $this;
    }

    /**
     * Add location to order by clause.
     *
     * @param string $column
     * @param float $lat
     * @param float $lng
     * @param string $direction
     *
     * @return $this
     */
    public function orderByLocation(string $column, float $lat, float $lng, string $direction): static
    {
        $this->locationOrderBy = [
            'column' => $column,
            'lat' => $lat,
            'lng' => $lng,
            'direction' => $direction,
        ];

        return $this;
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
