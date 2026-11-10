<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use ReflectionMethod;
use Siberfx\Typesense\Standalone\TypesenseStandaloneFacade;

test('facade accessor points at the manager binding', function () {
    $method = new ReflectionMethod(TypesenseStandaloneFacade::class, 'getFacadeAccessor');

    expect($method->invoke(null))->toBe('typesense.manager');
});
