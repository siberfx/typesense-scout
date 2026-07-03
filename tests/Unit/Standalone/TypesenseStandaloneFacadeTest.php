<?php

namespace Siberfx\Typesense\Tests\Unit\Standalone;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Siberfx\Typesense\Standalone\TypesenseStandaloneFacade;

class TypesenseStandaloneFacadeTest extends TestCase
{
    public function test_facade_accessor_points_at_the_manager_binding(): void
    {
        $method = new ReflectionMethod(TypesenseStandaloneFacade::class, 'getFacadeAccessor');
        $method->setAccessible(true);

        $this->assertSame('typesense.manager', $method->invoke(null));
    }
}
