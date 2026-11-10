<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Classes\CollectionIndexCache;
use Typesense\Collection;

class CollectionIndexCacheTest extends TestCase
{
    private function collection(): Collection
    {
        return $this->createStub(Collection::class);
    }

    public function test_resolves_each_collection_once(): void
    {
        $cache = new CollectionIndexCache();
        $calls = 0;
        $resolve = function () use (&$calls) {
            $calls++;

            return $this->collection();
        };

        $first = $cache->remember('todos', $resolve);
        $second = $cache->remember('todos', $resolve);

        $this->assertSame($first, $second);
        $this->assertSame(1, $calls);
    }

    public function test_keeps_collections_separate_by_name(): void
    {
        $cache = new CollectionIndexCache();

        $todos = $cache->remember('todos', fn () => $this->collection());
        $users = $cache->remember('users', fn () => $this->collection());

        $this->assertNotSame($todos, $users);
    }

    public function test_forget_makes_the_next_lookup_resolve_again(): void
    {
        $cache = new CollectionIndexCache();
        $cache->remember('todos', fn () => $this->collection());

        $cache->forget('todos');
        $fresh = $this->collection();

        $this->assertSame($fresh, $cache->remember('todos', fn () => $fresh));
    }

    public function test_forget_of_an_unknown_name_is_a_no_op(): void
    {
        $cache = new CollectionIndexCache();

        $cache->forget('never-seen');

        $this->assertInstanceOf(Collection::class, $cache->remember('never-seen', fn () => $this->collection()));
    }

    public function test_forget_all_clears_every_entry(): void
    {
        $cache = new CollectionIndexCache();
        $cache->remember('todos', fn () => $this->collection());
        $cache->remember('users', fn () => $this->collection());

        $cache->forgetAll();

        $calls = 0;
        $count = function () use (&$calls) {
            $calls++;

            return $this->collection();
        };
        $cache->remember('todos', $count);
        $cache->remember('users', $count);

        $this->assertSame(2, $calls);
    }
}
