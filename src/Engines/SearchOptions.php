<?php

namespace Siberfx\Typesense\Engines;

use WeakMap;

/**
 * The per-query Typesense options set through the Scout builder (groupBy, facetBy,
 * vector queries, multi-search, ...).
 *
 * Each builder owns one instance, kept in a WeakMap. Options therefore belong to the
 * query they were set on: they never leak into another search in the same process
 * (queue workers, Octane), and nothing has to be reset afterwards.
 */
final class SearchOptions
{
    /** @var WeakMap<object, self>|null */
    private static ?WeakMap $registry = null;

    public array $groupBy = [];

    public int $groupByLimit = 3;

    public string $startTag = '<mark>';

    public string $endTag = '</mark>';

    public int $limitHits = -1;

    /** @var array<string, mixed> Set by orderByLocation(): column, lat, lng and direction. */
    public array $locationOrderBy = [];

    public array $facetBy = [];

    public int $maxFacetValues = 10;

    public bool $useCache = false;

    public int $cacheTtl = 60;

    public int $snippetThreshold = 30;

    public bool $exhaustiveSearch = false;

    public bool $prioritizeExactMatch = true;

    public bool $enableOverrides = true;

    public int $highlightAffixNumTokens = 4;

    public string $facetQuery = '';

    public string $infix = 'off';

    public array $includeFields = [];

    public array $excludeFields = [];

    public array $highlightFields = [];

    public array $highlightFullFields = [];

    public array $pinnedHits = [];

    public array $hiddenHits = [];

    /** @var array<int, array> Searches for multi-search (searchMulti()). */
    public array $optionsMulti = [];

    public ?string $prefix = null;

    public string $vectorQuery = '';

    public array $synonymSets = [];

    public array $curationTags = [];

    public ?float $diversityLambda = null;

    public ?int $groupMaxCandidates = null;

    /**
     * The options for one builder, created on first use.
     */
    public static function for(object $builder): self
    {
        self::$registry ??= new WeakMap();

        return self::$registry[$builder] ??= new self();
    }

    /**
     * Build the vector_query for nearest-neighbour search against a vector field.
     *
     * @param array<int, float> $vector
     */
    public function nearestNeighbors(string $field, array $vector, int $k = 10, ?float $distanceThreshold = null, ?float $alpha = null): void
    {
        $options = ['k:' . $k];

        if ($distanceThreshold !== null) {
            $options[] = 'distance_threshold:' . $distanceThreshold;
        }

        if ($alpha !== null) {
            $options[] = 'alpha:' . $alpha;
        }

        $this->vectorQuery = sprintf('%s:([%s], %s)', $field, implode(', ', $vector), implode(', ', $options));
    }

    public function orderByLocation(string $column, float $lat, float $lng, string $direction): void
    {
        $this->locationOrderBy = [
            'column' => $column,
            'lat' => $lat,
            'lng' => $lng,
            'direction' => $direction,
        ];
    }
}
