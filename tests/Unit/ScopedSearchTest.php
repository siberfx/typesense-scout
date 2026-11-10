<?php

namespace Siberfx\Typesense\Tests\Unit;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use ReflectionProperty;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Scout\ScopedSearch;
use Siberfx\Typesense\Typesense;
use Typesense\Client;

class ScopedSearchStubModel extends Model
{
}

/**
 * A scoped search key must apply to the search it was set for, never to other
 * searches in the same process (queue workers, Octane, long-running commands).
 */

trait ScopedSearchTestHelpers
{
    protected function client(string $apiKey): Client
    {
        return new Client([
            'api_key' => $apiKey,
            'nodes' => [
                ['host' => 'localhost', 'port' => '8108', 'protocol' => 'http'],
            ],
            'connection_timeout_seconds' => 2,
        ]);
    }

    protected function scoped(string $apiKey, bool $softDelete = false): ScopedSearch
    {
        return new ScopedSearch(ScopedSearchStubModel::class, $this->client($apiKey), [], $softDelete);
    }

    /**
     * The engine Scout would use to execute this builder (Builder::engine() is protected).
     */
    protected function engineOf(Builder $builder): TypesenseEngine
    {
        return Closure::bind(fn () => $this->engine(), $builder, Builder::class)();
    }

    protected function apiKeyOf(TypesenseEngine $engine): string
    {
        $typesense = (new ReflectionProperty(TypesenseEngine::class, 'typesense'))->getValue($engine);
        $client = $typesense->getClient();
        $configuration = (new ReflectionProperty(Client::class, 'config'))->getValue($client);

        return $configuration->getApiKey();
    }
}

uses(ScopedSearchTestHelpers::class);

test('search uses its own scoped key', function () {
    $builder = $this->scoped('tenant-a-key')->search('todo');

    expect($builder)->toBeInstanceOf(Builder::class);
    expect($this->apiKeyOf($this->engineOf($builder)))->toBe('tenant-a-key');
});

test('scoped keys do not leak between searches', function () {
    $first = $this->engineOf($this->scoped('tenant-a-key')->search('todo'));
    $second = $this->engineOf($this->scoped('tenant-b-key')->search('todo'));

    expect($this->apiKeyOf($first))->toBe('tenant-a-key');
    expect($this->apiKeyOf($second))->toBe('tenant-b-key');
    expect($second)->not->toBe($first);
});

test('a later scoped search does not change an earlier builder', function () {
    $earlier = $this->scoped('tenant-a-key')->search('todo');
    $this->scoped('tenant-b-key')->search('todo');

    expect($this->apiKeyOf($this->engineOf($earlier)))->toBe('tenant-a-key');
});

test('soft delete flag is applied to the builder', function () {
    $withoutSoftDelete = $this->scoped('tenant-a-key', false)->search('todo');
    $withSoftDelete = $this->scoped('tenant-a-key', true)->search('todo');

    expect($withoutSoftDelete->wheres)->toHaveCount(0);
    expect($withSoftDelete->wheres)->toHaveCount(1);
});

test('the facade no longer changes the process wide engine', function () {
    expect(function () {
        $typesense = new Typesense($this->client('parent-key'));


        $typesense->setScopedApiKey('tenant-key');
    })->toThrow(BadMethodCallException::class, 'setScopedApiKey');
});
