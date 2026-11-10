<?php

// Tests run without a Laravel application, so register the Scout builder macros here,
// as the service provider does at boot.
require __DIR__ . '/../vendor/autoload.php';

use Laravel\Scout\Builder;
use Siberfx\Typesense\Mixin\BuilderMixin;

Builder::mixin(new BuilderMixin());
