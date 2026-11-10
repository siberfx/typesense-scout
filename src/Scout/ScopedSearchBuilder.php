<?php

namespace Siberfx\Typesense\Scout;

use Laravel\Scout\Builder;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * A Scout builder that runs on one specific engine instance.
 *
 * Scout normally resolves the engine from the shared EngineManager, which is
 * the same object for every search in the process. Binding the engine to the
 * builder keeps a scoped API key limited to the search it was created for.
 */
class ScopedSearchBuilder extends Builder
{
    public function __construct($model, $query, $callback, $softDelete, private readonly TypesenseEngine $scopedEngine)
    {
        parent::__construct($model, $query, $callback, $softDelete);
    }

    protected function engine()
    {
        return $this->scopedEngine;
    }
}
