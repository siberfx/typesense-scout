<?php

namespace Siberfx\Typesense\Concerns;

use Siberfx\Typesense\Typesense;

trait HasScopedApiKey
{
    /**
     * @param $key
     * @return static
     */
    public static function setScopedApiKey($key): static
    {
        app(Typesense::class)->setScopedApiKey($key);

        return new static;
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
