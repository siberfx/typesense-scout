<?php

namespace Siberfx\Typesense\Classes;

use Closure;
use Typesense\Collection;

/**
 * Keeps the Typesense collection handles that were already looked up, keyed by
 * collection name, so each search, import or delete does not repeat the
 * "does this collection exist?" request.
 */
final class CollectionIndexCache
{
    /** @var array<string, Collection> */
    private array $collections = [];

    /**
     * Return the cached handle for a collection, resolving it on first use.
     *
     * @param Closure(): Collection $resolve
     */
    public function remember(string $name, Closure $resolve): Collection
    {
        return $this->collections[$name] ??= $resolve();
    }

    public function forget(string $name): void
    {
        unset($this->collections[$name]);
    }

    public function forgetAll(): void
    {
        $this->collections = [];
    }
}
