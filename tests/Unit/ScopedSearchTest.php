<?php

namespace Siberfx\Typesense\Tests\Unit;

use BadMethodCallException;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
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
class ScopedSearchTest extends TestCase
{
    private function client(string $apiKey): Client
    {
        return new Client([
            'api_key' => $apiKey,
            'nodes' => [
                ['host' => 'localhost', 'port' => '8108', 'protocol' => 'http'],
            ],
            'connection_timeout_seconds' => 2,
        ]);
    }

    private function scoped(string $apiKey, bool $softDelete = false): ScopedSearch
    {
        return new ScopedSearch(ScopedSearchStubModel::class, $this->client($apiKey), [], $softDelete);
    }

    /**
     * The engine Scout would use to execute this builder (Builder::engine() is protected).
     */
    private function engineOf(Builder $builder): TypesenseEngine
    {
        return Closure::bind(fn () => $this->engine(), $builder, Builder::class)();
    }

    private function apiKeyOf(TypesenseEngine $engine): string
    {
        $typesense = (new ReflectionProperty(TypesenseEngine::class, 'typesense'))->getValue($engine);
        $client = $typesense->getClient();
        $configuration = (new ReflectionProperty(Client::class, 'config'))->getValue($client);

        return $configuration->getApiKey();
    }

    public function test_search_uses_its_own_scoped_key(): void
    {
        $builder = $this->scoped('tenant-a-key')->search('todo');

        $this->assertInstanceOf(Builder::class, $builder);
        $this->assertSame('tenant-a-key', $this->apiKeyOf($this->engineOf($builder)));
    }

    public function test_scoped_keys_do_not_leak_between_searches(): void
    {
        $first = $this->engineOf($this->scoped('tenant-a-key')->search('todo'));
        $second = $this->engineOf($this->scoped('tenant-b-key')->search('todo'));

        $this->assertSame('tenant-a-key', $this->apiKeyOf($first));
        $this->assertSame('tenant-b-key', $this->apiKeyOf($second));
        $this->assertNotSame($first, $second);
    }

    public function test_a_later_scoped_search_does_not_change_an_earlier_builder(): void
    {
        $earlier = $this->scoped('tenant-a-key')->search('todo');
        $this->scoped('tenant-b-key')->search('todo');

        $this->assertSame('tenant-a-key', $this->apiKeyOf($this->engineOf($earlier)));
    }

    public function test_soft_delete_flag_is_applied_to_the_builder(): void
    {
        $withoutSoftDelete = $this->scoped('tenant-a-key', false)->search('todo');
        $withSoftDelete = $this->scoped('tenant-a-key', true)->search('todo');

        $this->assertCount(0, $withoutSoftDelete->wheres);
        $this->assertCount(1, $withSoftDelete->wheres);
    }

    public function test_the_facade_no_longer_changes_the_process_wide_engine(): void
    {
        $typesense = new Typesense($this->client('parent-key'));

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('setScopedApiKey');

        $typesense->setScopedApiKey('tenant-key');
    }
}
