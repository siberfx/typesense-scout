<?php

namespace Siberfx\Typesense\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Facade;
use Laravel\Scout\EngineManager;
use ReflectionProperty;
use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Typesense;
use Siberfx\Typesense\TypesenseServiceProvider;

/**
 * Boots the service provider in a bare container (the package does not depend on
 * laravel/framework), with just the bindings the provider uses.
 */

class ServiceProviderTestApp extends Container
{
    public function runningInConsole(): bool
    {
        return false;
    }

    public function configPath($path = ''): string
    {
        return $path;
    }
}

class ServiceProviderTestConfig implements \ArrayAccess
{
    public function __construct(private array $items)
    {
    }

    public function get($key, $default = null): mixed
    {
        return Arr::get($this->items, $key, $default);
    }

    public function set($key, $value = null): void
    {
        Arr::set($this->items, $key, $value);
    }

    public function offsetExists(mixed $key): bool
    {
        return Arr::has($this->items, $key);
    }

    public function offsetGet(mixed $key): mixed
    {
        return $this->get($key);
    }

    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function offsetUnset(mixed $key): void
    {
        Arr::forget($this->items, $key);
    }
}

trait ServiceProviderTestHelpers
{
    protected function bootedApp(): ServiceProviderTestApp
    {
        $app = new ServiceProviderTestApp();
        $app->instance('config', new ServiceProviderTestConfig([
            'scout' => ['typesense' => ['client-settings' => [
                'api_key' => 'xyz',
                'nodes' => [['host' => 'localhost', 'port' => '8108', 'protocol' => 'http']],
            ]]],
        ]));
        $app->singleton(EngineManager::class, static fn ($app) => new EngineManager($app));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        $provider = new TypesenseServiceProvider($app);
        $provider->register();
        $provider->boot();

        return $app;
    }
}

uses(ServiceProviderTestHelpers::class);

afterEach(function () {
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(null);
});

test('the scout engine shares the typesense singleton', function () {
    $app = $this->bootedApp();

    $engine = $app->make(EngineManager::class)->engine('typesense');

    expect($engine)->toBeInstanceOf(TypesenseEngine::class);
    expect((new ReflectionProperty(TypesenseEngine::class, 'typesense'))->getValue($engine))
        ->toBe($app->make(Typesense::class));
});
