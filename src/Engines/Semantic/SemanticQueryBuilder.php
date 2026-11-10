<?php

namespace Siberfx\Typesense\Engines\Semantic;

use Laravel\Scout\Builder;
use Laravel\Scout\Exceptions\ScoutException;
use Siberfx\Typesense\Engines\SearchOptions;

/**
 * Turns a semantic(), hybrid() or nearestNeighbors() query into the Typesense
 * query_by, prefix, exclude_fields and vector_query parameters.
 */
final class SemanticQueryBuilder
{
    /**
     * @param \Closure(array<int, string>, array<string, mixed>): array $embedder Generates query embeddings.
     */
    public function __construct(private readonly EmbeddingSettings $embeddings, private readonly \Closure $embedder)
    {
    }

    /**
     * Apply Scout's semantic() / hybrid() builder state to the search params.
     *
     * @param \Laravel\Scout\Builder $builder
     * @param array $params
     *
     * @return array
     */
    public function apply(Builder $builder, array $params, SearchOptions $options): array
    {
        if (!$builder->semanticSearch && $builder->hybridSearch === null) {
            return $params;
        }

        if ($options->vectorQuery !== '') {
            throw new ScoutException('Typesense semantic and hybrid searches cannot be combined with vectorQuery() or nearestNeighbors().');
        }

        $settings = $this->embeddings->settings($builder->model);

        if ($builder->semanticSearch) {
            $params = $this->embeddings->usesNative($settings)
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

        if (!$this->embeddings->usesNative($settings) || in_array($settings['attribute'], $fields, true)) {
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
        if ($this->embeddings->usesNative($settings)) {
            $vector = [];
        } else {
            $vector = $builder->options['vector'] ?? ($this->embedder)([$builder->query], $settings)[0];

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
}
