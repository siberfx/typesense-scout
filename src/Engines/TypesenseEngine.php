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

        $settings = $this->hasEmbeddingSettings($models->first())
            ? $this->embeddingSettings($models->first())
            : null;

        $documents = $settings !== null && !$this->usesNativeEmbeddings($settings)
            ? $this->addEmbeddingsToDocuments($records, $settings)
            : array_column($records, 'document');

        $this->typesense->withCollection(
            $models->first(),
            fn ($collection) => $this->typesense->importDocuments($collection, $documents)
        );
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
        $searchOptions = SearchOptions::for($builder);

        if (!$builder->semanticSearch && $builder->hybridSearch === null) {
            return $params;
        }

        if ($searchOptions->vectorQuery !== '') {
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
            : array_map([$this, 'quoteListValue'], $this->parseFilterValue($values));

        // Scout 11 stores where() clauses as ['field', 'operator', 'value']
        // entries; plain `field => value` pairs are still accepted.
        $whereFilter = collect($builder->wheres)
            ->map(fn ($where, $key) => is_array($where) && array_key_exists('field', $where) && array_key_exists('value', $where)
                ? $this->parseWhereFilter($whereValue($where['value']), $where['field'], $where['operator'] ?? '=')
                : $this->parseWhereFilter($whereValue($where), $key))
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
     * Quote a whereIn/whereNotIn list item when it would otherwise break the list.
     *
     * Plain values (words, numbers, booleans) stay unquoted, so the common output
     * is unchanged. A string with a comma, space, bracket or backtick, or an empty
     * string, is quoted, because `[a,b]` is read as two items.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function quoteListValue(mixed $value): mixed
    {
        if (is_string($value) && !is_numeric($value)
            && ($value === '' || preg_match('/[^\w.\-@\/]/u', $value) === 1)) {
            return static::escapeFilterValue($value);
        }

        return $value;
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
     * Scout's own operator form works too: where('price', '>', 100).
     *
     * @param array|string $value
     * @param string $key
     * @param string $operator One of =, !=, <, >, <=, >=.
     *
     * @return string
     */
    public function parseWhereFilter(array|string $value, string $key, string $operator = '='): string
    {
        if (is_array($value)) {
            return sprintf('%s:%s', $key, implode('', $value));
        }

        $operator = match ($operator) {
            '!=', '<>' => ':!=',
            '<' => ':<',
            '>' => ':>',
            '<=' => ':<=',
            '>=' => ':>=',
            default => ':=',
        };

        return sprintf('%s%s%s', $key, $operator, $value);
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
