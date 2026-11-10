<?php

/**
 * Guards the public admin API surface (synonyms, overrides/curation, aliases,
 * analytics rules) on the Typesense wrapper. These are thin delegations to the
 * typesense-php client; live HTTP behaviour is exercised by the integration
 * suite / CI against a running Typesense server.
 */

use Siberfx\Typesense\Typesense;

// method => [method name, number of required parameters]
dataset('admin methods', [

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
            // Presets
            'upsertPreset' => ['upsertPreset', 2],
            'retrievePresets' => ['retrievePresets', 0],
            'retrievePreset' => ['retrievePreset', 1],
            'deletePreset' => ['deletePreset', 1],
            // Stopwords
            'upsertStopword' => ['upsertStopword', 2],
            'retrieveStopwords' => ['retrieveStopwords', 0],
            'retrieveStopword' => ['retrieveStopword', 1],
            'deleteStopword' => ['deleteStopword', 1],
            // Stemming dictionaries
            'upsertStemmingDictionary' => ['upsertStemmingDictionary', 2],
            'retrieveStemmingDictionaries' => ['retrieveStemmingDictionaries', 0],
            'retrieveStemmingDictionary' => ['retrieveStemmingDictionary', 1],
            // Conversation models (RAG)
            'createConversationModel' => ['createConversationModel', 1],
            'retrieveConversationModels' => ['retrieveConversationModels', 0],
            'retrieveConversationModel' => ['retrieveConversationModel', 1],
            'updateConversationModel' => ['updateConversationModel', 2],
            'deleteConversationModel' => ['deleteConversationModel', 1],
            // Natural language search models
            'createNLSearchModel' => ['createNLSearchModel', 1],
            'retrieveNLSearchModels' => ['retrieveNLSearchModels', 0],
            'retrieveNLSearchModel' => ['retrieveNLSearchModel', 1],
            'updateNLSearchModel' => ['updateNLSearchModel', 2],
            'deleteNLSearchModel' => ['deleteNLSearchModel', 1],
]);

test('admin method exists and is public with expected arity', function (string $method, int $requiredParams) {
    expect(method_exists(Typesense::class, $method))->toBeTrue("Typesense::{$method}() should exist.");

    $reflection = new ReflectionMethod(Typesense::class, $method);

    expect($reflection->isPublic())->toBeTrue("Typesense::{$method}() should be public.");
    expect($reflection->getNumberOfRequiredParameters())->toBe($requiredParams, "Typesense::{$method}() should require {$requiredParams} parameters.");
    expect((string) $reflection->getReturnType())->toBe('array', "Typesense::{$method}() should return array.");
})->with('admin methods');
