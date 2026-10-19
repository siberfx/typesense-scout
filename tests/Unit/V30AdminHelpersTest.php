<?php

namespace Siberfx\Typesense\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Siberfx\Typesense\Typesense;
use Typesense\Analytics;
use Typesense\ApiCall;
use Typesense\Client;
use Typesense\CurationSets;
use Typesense\Keys;
use Typesense\SynonymSets;

/**
 * Server v30 admin helpers on the Typesense wrapper (synonym sets, curation
 * sets and their items, API keys, analytics events). Real typesense-php
 * resources over a mocked ApiCall, so the HTTP verb and endpoint are checked.
 */
class V30AdminHelpersTest extends TestCase
{
    private function typesense(ApiCall $apiCall): Typesense
    {
        $client = $this->createStub(Client::class);
        $client->method('getSynonymSets')->willReturn(new SynonymSets($apiCall));
        $client->method('getCurationSets')->willReturn(new CurationSets($apiCall));
        $client->method('getKeys')->willReturn(new Keys($apiCall));
        $client->method('getAnalytics')->willReturn(new Analytics($apiCall));

        return new Typesense($client);
    }

    private function expectCall(string $verb, array $with): ApiCall
    {
        $apiCall = $this->createMock(ApiCall::class);
        $apiCall->expects($this->once())->method($verb)->with(...$with)->willReturn(['ok' => true]);

        return $apiCall;
    }

    public function test_synonym_set_helpers(): void
    {
        $config = ['items' => [['id' => 'coat', 'synonyms' => ['coat', 'jacket']]]];

        $this->assertSame(['ok' => true], $this->typesense($this->expectCall('put', ['/synonym_sets/clothing', $config]))->upsertSynonymSet('clothing', $config));
        $this->typesense($this->expectCall('get', ['/synonym_sets', []]))->retrieveSynonymSets();
        $this->typesense($this->expectCall('get', ['/synonym_sets/clothing', []]))->retrieveSynonymSet('clothing');
        $this->typesense($this->expectCall('delete', ['/synonym_sets/clothing']))->deleteSynonymSet('clothing');
    }

    public function test_curation_set_helpers(): void
    {
        $config = ['items' => [['id' => 'pin', 'rule' => ['query' => 'apple', 'match' => 'exact']]]];

        $this->typesense($this->expectCall('put', ['/curation_sets/products', $config]))->upsertCurationSet('products', $config);
        $this->typesense($this->expectCall('get', ['/curation_sets', []]))->retrieveCurationSets();
        $this->typesense($this->expectCall('get', ['/curation_sets/products', []]))->retrieveCurationSet('products');
        $this->typesense($this->expectCall('delete', ['/curation_sets/products']))->deleteCurationSet('products');
    }

    public function test_curation_set_item_helpers(): void
    {
        $item = ['rule' => ['query' => 'apple', 'match' => 'exact'], 'includes' => [['id' => '42', 'position' => 1]]];

        $this->typesense($this->expectCall('put', ['/curation_sets/products/items/pin', $item]))->upsertCurationSetItem('products', 'pin', $item);
        $this->typesense($this->expectCall('get', ['/curation_sets/products/items', []]))->retrieveCurationSetItems('products');
        $this->typesense($this->expectCall('get', ['/curation_sets/products/items/pin', []]))->retrieveCurationSetItem('products', 'pin');
        $this->typesense($this->expectCall('delete', ['/curation_sets/products/items/pin']))->deleteCurationSetItem('products', 'pin');
    }

    public function test_api_key_helpers(): void
    {
        $this->typesense($this->expectCall('get', ['/keys', []]))->retrieveApiKeys();
        $this->typesense($this->expectCall('get', ['/keys/7', []]))->retrieveApiKey(7);
        $this->typesense($this->expectCall('delete', ['/keys/7']))->deleteApiKey(7);
    }

    public function test_analytics_event_helpers(): void
    {
        $event = ['name' => 'clicks', 'event_type' => 'click', 'data' => ['doc_id' => '1', 'user_id' => 'u1']];

        $this->typesense($this->expectCall('post', ['/analytics/events', $event]))->createAnalyticsEvent($event);
        $this->typesense($this->expectCall('get', ['/analytics/events', ['user_id' => 'u1', 'name' => 'clicks', 'n' => 10]]))
            ->retrieveAnalyticsEvents(['user_id' => 'u1', 'name' => 'clicks', 'n' => 10]);
    }
}
