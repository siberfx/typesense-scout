<?php

namespace Siberfx\Typesense\Mixin;

use Closure;
use Siberfx\Typesense\Engines\SearchOptions;

/**
 * Typesense-specific methods added to Scout's Builder. Each one stores its option on
 * the builder itself (see SearchOptions), so it applies to this query only.
 */
class BuilderMixin
{
    /**
     * Total number of matches for the query.
     */
    public function count(): Closure
    {
        return function () {
            return $this->engine()
                        ->getTotalCount($this->engine()
                                             ->search($this));
        };
    }

    public function orderByLocation(): Closure
    {
        return function (string $column, float $lat, float $lng, string $direction = 'asc') {
            SearchOptions::for($this)->orderByLocation($column, $lat, $lng, $direction);

            return $this;
        };
    }

    public function groupBy(): Closure
    {
        return function (array|string $groupBy) {
            SearchOptions::for($this)->groupBy = is_array($groupBy) ? $groupBy : func_get_args();

            return $this;
        };
    }

    public function groupByLimit(): Closure
    {
        return function (int $groupByLimit) {
            SearchOptions::for($this)->groupByLimit = $groupByLimit;

            return $this;
        };
    }

    public function setHighlightStartTag(): Closure
    {
        return function (string $startTag) {
            SearchOptions::for($this)->startTag = $startTag;

            return $this;
        };
    }

    public function setHighlightEndTag(): Closure
    {
        return function (string $endTag) {
            SearchOptions::for($this)->endTag = $endTag;

            return $this;
        };
    }

    public function limitHits(): Closure
    {
        return function (int $limitHits) {
            SearchOptions::for($this)->limitHits = $limitHits;

            return $this;
        };
    }

    public function facetBy(): Closure
    {
        return function (array $facetBy) {
            SearchOptions::for($this)->facetBy = $facetBy;

            return $this;
        };
    }

    public function setMaxFacetValues(): Closure
    {
        return function (int $maxFacetValues) {
            SearchOptions::for($this)->maxFacetValues = $maxFacetValues;

            return $this;
        };
    }

    public function facetQuery(): Closure
    {
        return function (string $facetQuery) {
            SearchOptions::for($this)->facetQuery = $facetQuery;

            return $this;
        };
    }

    public function setIncludeFields(): Closure
    {
        return function (array $includeFields) {
            SearchOptions::for($this)->includeFields = $includeFields;

            return $this;
        };
    }

    public function setExcludeFields(): Closure
    {
        return function (array $excludeFields) {
            SearchOptions::for($this)->excludeFields = $excludeFields;

            return $this;
        };
    }

    public function setHighlightFields(): Closure
    {
        return function (array $highlightFields) {
            SearchOptions::for($this)->highlightFields = $highlightFields;

            return $this;
        };
    }

    public function setPinnedHits(): Closure
    {
        return function (array $pinnedHits) {
            SearchOptions::for($this)->pinnedHits = $pinnedHits;

            return $this;
        };
    }

    public function setHiddenHits(): Closure
    {
        return function (array $hiddenHits) {
            SearchOptions::for($this)->hiddenHits = $hiddenHits;

            return $this;
        };
    }

    public function setHighlightFullFields(): Closure
    {
        return function (array $highlightFullFields) {
            SearchOptions::for($this)->highlightFullFields = $highlightFullFields;

            return $this;
        };
    }

    public function setHighlightAffixNumTokens(): Closure
    {
        return function (int $highlightAffixNumTokens) {
            SearchOptions::for($this)->highlightAffixNumTokens = $highlightAffixNumTokens;

            return $this;
        };
    }

    public function setInfix(): Closure
    {
        return function (string $infix) {
            SearchOptions::for($this)->infix = $infix;

            return $this;
        };
    }

    public function setSnippetThreshold(): Closure
    {
        return function (int $snippetThreshold) {
            SearchOptions::for($this)->snippetThreshold = $snippetThreshold;

            return $this;
        };
    }

    public function exhaustiveSearch(): Closure
    {
        return function (bool $exhaustiveSearch) {
            SearchOptions::for($this)->exhaustiveSearch = $exhaustiveSearch;

            return $this;
        };
    }

    public function setUseCache(): Closure
    {
        return function (bool $useCache) {
            SearchOptions::for($this)->useCache = $useCache;

            return $this;
        };
    }

    public function setCacheTtl(): Closure
    {
        return function (int $cacheTtl) {
            SearchOptions::for($this)->cacheTtl = $cacheTtl;

            return $this;
        };
    }

    public function setPrioritizeExactMatch(): Closure
    {
        return function (bool $prioritizeExactMatch) {
            SearchOptions::for($this)->prioritizeExactMatch = $prioritizeExactMatch;

            return $this;
        };
    }

    public function setPrefix(): Closure
    {
        return function (string $prefix) {
            SearchOptions::for($this)->prefix = $prefix;

            return $this;
        };
    }

    public function enableOverrides(): Closure
    {
        return function (bool $enableOverrides) {
            SearchOptions::for($this)->enableOverrides = $enableOverrides;

            return $this;
        };
    }

    public function searchMulti(): Closure
    {
        return function (array $searchRequests) {
            SearchOptions::for($this)->optionsMulti = $searchRequests;

            return $this;
        };
    }

    public function vectorQuery(): Closure
    {
        return function (string $vectorQuery) {
            SearchOptions::for($this)->vectorQuery = $vectorQuery;

            return $this;
        };
    }

    public function nearestNeighbors(): Closure
    {
        return function (string $field, array $vector, int $k = 10, ?float $distanceThreshold = null, ?float $alpha = null) {
            SearchOptions::for($this)->nearestNeighbors($field, $vector, $k, $distanceThreshold, $alpha);

            return $this;
        };
    }

    public function synonymSets(): Closure
    {
        return function (array|string $synonymSets) {
            SearchOptions::for($this)->synonymSets = (array) $synonymSets;

            return $this;
        };
    }

    public function curationTags(): Closure
    {
        return function (array|string $curationTags) {
            SearchOptions::for($this)->curationTags = (array) $curationTags;

            return $this;
        };
    }

    public function diversityLambda(): Closure
    {
        return function (float $lambda) {
            SearchOptions::for($this)->diversityLambda = $lambda;

            return $this;
        };
    }

    public function groupMaxCandidates(): Closure
    {
        return function (int $groupMaxCandidates) {
            SearchOptions::for($this)->groupMaxCandidates = $groupMaxCandidates;

            return $this;
        };
    }
}
