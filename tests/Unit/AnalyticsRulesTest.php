<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Typesense;
use Typesense\Analytics;
use Typesense\ApiCall;
use Typesense\Client;

/**
 * The analytics rule helpers must use the v30 API (typesense-php 6.x), which
 * has no rules()->upsert(): upserts are a PUT to /analytics/rules/{name}.
 */
class AnalyticsRulesTest extends TestCase
{
    private function typesense(ApiCall $apiCall): Typesense
    {
        $client = $this->createStub(Client::class);
        $client->method('getAnalytics')->willReturn(new Analytics($apiCall));

        return new Typesense($client);
    }

    public function test_upsert_analytics_rule_puts_the_named_rule(): void
    {
        $params = ['type' => 'popular_queries', 'collection' => 'products', 'event_type' => 'search'];

        $apiCall = $this->createMock(ApiCall::class);
        $apiCall->expects($this->once())
            ->method('put')
            ->with('/analytics/rules/product_queries', $params)
            ->willReturn(['name' => 'product_queries'] + $params);

        $result = $this->typesense($apiCall)->upsertAnalyticsRule('product_queries', $params);

        $this->assertSame('product_queries', $result['name']);
    }
}
