<?php

namespace Siberfx\Typesense;

use Siberfx\Typesense\Engines\TypesenseEngine;
use Siberfx\Typesense\Mixin\BuilderMixin;
use Siberfx\Typesense\Standalone\TypesenseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\Builder;
use Laravel\Scout\EngineManager;
use Typesense\Client;

/**
 * Class TypesenseServiceProvider.
 *
 * @date    4/5/20
 *
 * @author  Selim Görmüş <info@siberfx.com>
 */
class TypesenseServiceProvider extends ServiceProvider
{
    /**
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    public function boot(): void
    {
        $this->app[EngineManager::class]->extend('typesense', static function ($app) {
            $client = new Client(Config::get('scout.typesense.client-settings'));

            return new TypesenseEngine(new Typesense($client));
        });

        $this->registerMacros();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/typesense.php' => $this->app->configPath('typesense.php'),
            ], 'typesense-config');
        }
    }

    /**
     * Register singletons and aliases.
     */
    public function register(): void
    {
        $this->app->singleton(Typesense::class, static function () {
            $client = new Client(Config::get('scout.typesense.client-settings'));

            return new Typesense($client);
        });

        $this->app->alias(Typesense::class, 'typesense');

        $this->mergeConfigFrom(__DIR__ . '/../config/typesense.php', 'typesense');

        $this->app->singleton('typesense.manager', static function ($app) {
            return TypesenseManager::fromConfig(
                $app['config']->get('typesense', []),
                $app['config']->get('scout.typesense.client-settings', []),
            );
        });

        $this->app->alias('typesense.manager', TypesenseManager::class);
    }

    /**
     * @throws \ReflectionException
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    private function registerMacros(): void
    {
        Builder::mixin($this->app->make(BuilderMixin::class));
    }
}
