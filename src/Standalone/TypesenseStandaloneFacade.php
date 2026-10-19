<?php

namespace Siberfx\Typesense\Standalone;

use Illuminate\Support\Facades\Facade;

/**
 * @method static TypesenseConnection connection(?string $name = null)
 * @method static array  createCollection(array $schema, array $options = [])
 * @method static array  cloneCollection(string $source, string $name, bool $copyDocuments = false)
 * @method static array  ensureCollection(array $schema)
 * @method static bool   hasCollection(string $name)
 * @method static array  dropCollection(string $name)
 * @method static array  listCollections()
 * @method static DocumentActions documents(string $collection)
 * @method static array  search(string $collection, array $params)
 * @method static array  multiSearch(array $searches, array $common = [], array $body = [])
 * @method static array  unionSearch(array $searches, array $common = [], array $options = [])
 * @method static string generateScopedSearchKey(string $searchKey, array $parameters)
 * @method static \Typesense\SynonymSets  synonymSets()
 * @method static \Typesense\CurationSets curationSets()
 * @method static \Typesense\AnalyticsV1  analyticsV1()
 *
 * @see \Siberfx\Typesense\Standalone\TypesenseManager
 */
class TypesenseStandaloneFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'typesense.manager';
    }
}
