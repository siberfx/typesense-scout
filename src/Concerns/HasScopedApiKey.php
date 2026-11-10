<?php

namespace Siberfx\Typesense\Concerns;

use Illuminate\Database\Eloquent\SoftDeletes;
use Siberfx\Typesense\Scout\ScopedSearch;
use Siberfx\Typesense\Typesense;

trait HasScopedApiKey
{
    /**
     * Search this model with a scoped API key. The key applies only to the
     * search that follows, for example:
     *
     *     Todo::setScopedApiKey($key)->search('term')->get();
     *
     * @param string $key
     * @return ScopedSearch
     */
    public static function setScopedApiKey($key): ScopedSearch
    {
        $softDelete = in_array(SoftDeletes::class, class_uses_recursive(static::class), true)
            && config('scout.soft_delete', false);

        return app(Typesense::class)->scopedSearch(static::class, $key, $softDelete);
    }

    /**
     * Generate a Typesense scoped search key embedding the given parameters
     * (e.g. a tenant `filter_by` and/or `expires_at`).
     *
     * @param string $searchKey A parent search-only API key.
     * @param array  $parameters Embedded search parameters.
     *
     * @return string
     * @throws \JsonException
     */
    public static function generateScopedSearchKey(string $searchKey, array $parameters): string
    {
        return app(Typesense::class)->generateScopedSearchKey($searchKey, $parameters);
    }
}
