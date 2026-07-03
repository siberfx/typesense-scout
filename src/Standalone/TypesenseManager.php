<?php

namespace Siberfx\Typesense\Standalone;

use InvalidArgumentException;

/**
 * Holds named standalone Typesense connections. The default connection inherits
 * the Scout client settings as a fallback; named connections stand alone.
 */
class TypesenseManager
{
    private string $defaultConnection;

    /** @var array<string, array> */
    private array $connectionsConfig;

    /** @var array<string, TypesenseConnection> */
    private array $resolved = [];

    private TypesenseConnectionFactory $factory;

    /**
     * @param array $config        The `config('typesense')` array.
     * @param array $scoutFallback The `config('scout.typesense.client-settings')` array.
     */
    public function __construct(
        array $config,
        private readonly array $scoutFallback = [],
        ?TypesenseConnectionFactory $factory = null,
    ) {
        $this->defaultConnection = $config['default'] ?? 'default';
        $this->connectionsConfig = $config['connections'] ?? [];
        $this->factory = $factory ?? new TypesenseConnectionFactory();
    }

    public static function fromConfig(
        array $config,
        array $scoutFallback = [],
        ?TypesenseConnectionFactory $factory = null,
    ): self {
        return new self($config, $scoutFallback, $factory);
    }

    /**
     * Resolve the final client settings for a connection. The default
     * connection (and only it) inherits the Scout client settings.
     */
    public function resolveSettingsFor(?string $name = null): array
    {
        $name ??= $this->defaultConnection;

        if (! array_key_exists($name, $this->connectionsConfig)) {
            throw new InvalidArgumentException("Typesense connection [{$name}] is not configured.");
        }

        $fallback = $name === $this->defaultConnection ? $this->scoutFallback : [];

        return $this->factory->resolveSettings($this->connectionsConfig[$name], $fallback);
    }

    public function connection(?string $name = null): TypesenseConnection
    {
        $name ??= $this->defaultConnection;

        return $this->resolved[$name] ??= new TypesenseConnection(
            $this->factory->make($this->resolveSettingsFor($name))
        );
    }

    /**
     * Proxy calls to the default connection so the facade reads fluently, e.g.
     * TypesenseDirect::search(...).
     */
    public function __call(string $method, array $parameters)
    {
        return $this->connection()->{$method}(...$parameters);
    }
}
