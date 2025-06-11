<?php

namespace Siberfx\Typesense\Interfaces;

/**
 * Interface TypesenseSearch
 *
 * @package Siberfx\Typesense\Interfaces
 */
interface TypesenseDocument
{
    public function typesenseQueryBy(): array;

    public function getCollectionSchema(): array;
}
