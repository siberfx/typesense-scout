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
}
