<?php

namespace Siberfx\Typesense\Tests\Unit;

/**
 * Guards installation requirements that the code relies on but Composer does not enforce.
 */

trait PackageMetadataTestHelpers
{
    protected function composer(): array
    {
        return json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
    }
}

uses(PackageMetadataTestHelpers::class);

test('a psr18 http client is a runtime requirement', function () {
    // typesense/typesense-php needs a PSR-18 client to send any request. Without this
    // package, apps get "Unable to find a PSR-18 client" on their first search.
    expect($this->composer()['require'])->toHaveKey('php-http/guzzle7-adapter');
});
