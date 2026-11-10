<?php

namespace Siberfx\Typesense\Tests\Unit;

use Siberfx\Typesense\Classes\CollectionIndexCache;
use Typesense\Collection;

trait CollectionIndexCacheTestHelpers
{
    protected function collection(): Collection
    {
        return $this->createStub(Collection::class);
    }
}

uses(CollectionIndexCacheTestHelpers::class);

test('resolves each collection once', function () {
    $cache = new CollectionIndexCache();
    $calls = 0;
    $resolve = function () use (&$calls) {
        $calls++;

        return $this->collection();
    };

    $first = $cache->remember('todos', $resolve);
    $second = $cache->remember('todos', $resolve);

    expect($second)->toBe($first);
    expect($calls)->toBe(1);
});

test('keeps collections separate by name', function () {
    $cache = new CollectionIndexCache();

    $todos = $cache->remember('todos', fn () => $this->collection());
    $users = $cache->remember('users', fn () => $this->collection());

    expect($users)->not->toBe($todos);
});

test('forget makes the next lookup resolve again', function () {
    $cache = new CollectionIndexCache();
    $cache->remember('todos', fn () => $this->collection());

    $cache->forget('todos');
    $fresh = $this->collection();

    expect($cache->remember('todos', fn () => $fresh))->toBe($fresh);
});

test('forget of an unknown name is a no op', function () {
    $cache = new CollectionIndexCache();

    $cache->forget('never-seen');

    expect($cache->remember('never-seen', fn () => $this->collection()))->toBeInstanceOf(Collection::class);
});

test('forget all clears every entry', function () {
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

    expect($calls)->toBe(2);
});
