<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Siberfx\Typesense\Typesense;

/**
 * Guards the public admin API surface (synonyms, overrides/curation, aliases,
 * analytics rules) on the Typesense wrapper. These are thin delegations to the
 * typesense-php client; live HTTP behaviour is exercised by the integration
 * suite / CI against a running Typesense server.
 */
class AdminApiSurfaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int}> method => required params
     */
    public static function adminMethods(): array
    {
        return [
            // Synonyms
            'upsertSynonym' => ['upsertSynonym', 3],
            'retrieveSynonyms' => ['retrieveSynonyms', 1],
            'retrieveSynonym' => ['retrieveSynonym', 2],
            'deleteSynonym' => ['deleteSynonym', 2],
            // Overrides / curation
            'upsertOverride' => ['upsertOverride', 3],
            'retrieveOverrides' => ['retrieveOverrides', 1],
            'retrieveOverride' => ['retrieveOverride', 2],
            'deleteOverride' => ['deleteOverride', 2],
            // Aliases
            'upsertAlias' => ['upsertAlias', 2],
            'retrieveAliases' => ['retrieveAliases', 0],
            'retrieveAlias' => ['retrieveAlias', 1],
            'deleteAlias' => ['deleteAlias', 1],
            // Analytics rules
            'upsertAnalyticsRule' => ['upsertAnalyticsRule', 2],
            'retrieveAnalyticsRules' => ['retrieveAnalyticsRules', 0],
            'retrieveAnalyticsRule' => ['retrieveAnalyticsRule', 1],
            'deleteAnalyticsRule' => ['deleteAnalyticsRule', 1],
        ];
    }

    #[DataProvider('adminMethods')]
    public function test_admin_method_exists_and_is_public_with_expected_arity(string $method, int $requiredParams): void
    {
        $this->assertTrue(
            method_exists(Typesense::class, $method),
            "Typesense::{$method}() should exist."
        );

        $reflection = new ReflectionMethod(Typesense::class, $method);

        $this->assertTrue($reflection->isPublic(), "Typesense::{$method}() should be public.");
        $this->assertSame(
            $requiredParams,
            $reflection->getNumberOfRequiredParameters(),
            "Typesense::{$method}() should require {$requiredParams} parameters."
        );
        $this->assertSame(
            'array',
            (string) $reflection->getReturnType(),
            "Typesense::{$method}() should return array."
        );
    }
}
