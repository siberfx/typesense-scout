<?php

namespace Siberfx\Typesense\Scout;

use Laravel\Scout\Builder;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Typesense\Client;

/**
 * Starts one search that authenticates with a scoped API key.
 *
 * Returned by `Model::setScopedApiKey($key)`. Nothing is stored globally: the
 * key is used only by the builder this object creates.
 */
final class ScopedSearch
{
    /**
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param array<string, mixed> $engineConfig The `scout.typesense` configuration.
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly Client $client,
        private readonly array $engineConfig,
        private readonly bool $softDelete = false,
    ) {
    }

    /**
     * @param string $query
     * @param callable|null $callback
     */
    public function search(string $query = '', $callback = null): Builder
    {
        return new ScopedSearchBuilder(
            new $this->modelClass,
            $query,
            $callback,
            $this->softDelete,
            new TypesenseEngine(new Typesense($this->client), $this->engineConfig),
        );
    }
}
