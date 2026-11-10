<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards installation requirements that the code relies on but Composer does not enforce.
 */
class PackageMetadataTest extends TestCase
{
    private function composer(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_a_psr18_http_client_is_a_runtime_requirement(): void
    {
        // typesense/typesense-php needs a PSR-18 client to send any request. Without this
        // package, apps get "Unable to find a PSR-18 client" on their first search.
        $this->assertArrayHasKey(
            'php-http/guzzle7-adapter',
            $this->composer()['require'],
            'php-http/guzzle7-adapter must be a runtime requirement, not only a dev one.'
        );
    }
}
