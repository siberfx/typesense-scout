<?php

namespace Siberfx\Typesense\Tests\Unit;

use Laravel\Scout\Builder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Siberfx\Typesense\Engines\TypesenseEngine;

/**
 * Typesense server v30 search parameters: synonym sets, curation tags,
 * diversity (MMR), group_max_candidates and the Scout options() passthrough.
 */
class V30SearchParamsTest extends TestCase
{
    private function engine(): TypesenseEngine
    {
        // Avoid the constructor so we don't need a live Typesense client.
        return (new ReflectionClass(TypesenseEngine::class))->newInstanceWithoutConstructor();
    }

    private function builder(string $query = 'shoes'): Builder
    {
        $model = new class {
            public function typesenseQueryBy(): array
            {
                return ['name'];
            }
        };

        return new Builder($model, $query);
    }

    private function invokePrivate(TypesenseEngine $engine, string $method, array $args = []): mixed
    {
        return (new ReflectionClass(TypesenseEngine::class))->getMethod($method)->invoke($engine, ...$args);
    }

    private function params(TypesenseEngine $engine, ?Builder $builder = null): array
    {
        $params = $this->invokePrivate($engine, 'buildSearchParams', [$builder ?? $this->builder(), 1, 10]);

        return $this->invokePrivate($engine, 'filterSearchParams', [$params]);
    }

    public function test_synonym_sets_and_curation_tags_are_sent_as_comma_lists(): void
    {
        $engine = $this->engine();
        $engine->synonymSets(['clothing', 'tech']);
        $engine->curationTags('summer');

        $params = $this->params($engine);

        $this->assertSame('clothing,tech', $params['synonym_sets']);
        $this->assertSame('summer', $params['curation_tags']);
    }

    public function test_diversity_lambda_and_group_max_candidates_are_sent(): void
    {
        $engine = $this->engine();
        $engine->diversityLambda(0.75);
        $engine->groupMaxCandidates(500);

        $params = $this->params($engine);

        $this->assertSame(0.75, $params['diversity_lambda']);
        $this->assertSame(500, $params['group_max_candidates']);
    }

    public function test_unset_v30_params_are_not_sent(): void
    {
        $params = $this->params($this->engine());

        foreach (['synonym_sets', 'curation_tags', 'diversity_lambda', 'group_max_candidates'] as $key) {
            $this->assertArrayNotHasKey($key, $params);
        }
    }

    public function test_builder_options_are_passed_through_and_override_defaults(): void
    {
        $builder = $this->builder()->options([
            'facet_sample_slope' => 0.5,
            'facet_return_parent' => 'variants.color',
            'prioritize_exact_match' => false,
        ]);

        $params = $this->params($this->engine(), $builder);

        $this->assertSame(0.5, $params['facet_sample_slope']);
        $this->assertSame('variants.color', $params['facet_return_parent']);
        $this->assertFalse($params['prioritize_exact_match']);
    }

    public function test_scout_vector_option_is_not_sent_as_a_search_param(): void
    {
        $builder = $this->builder()->options(['vector' => [0.1, 0.2]]);

        $this->assertArrayNotHasKey('vector', $this->params($this->engine(), $builder));
    }

    public function test_v30_params_are_reset_after_a_search(): void
    {
        $engine = $this->engine();
        $engine->synonymSets('clothing');
        $engine->curationTags('summer');
        $engine->diversityLambda(0.3);
        $engine->groupMaxCandidates(100);

        $this->invokePrivate($engine, 'resetSearchParameters');

        $this->assertSame([], array_intersect_key(
            $this->params($engine),
            array_flip(['synonym_sets', 'curation_tags', 'diversity_lambda', 'group_max_candidates'])
        ));
    }
}
