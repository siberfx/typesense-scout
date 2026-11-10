<?php

namespace Siberfx\Typesense\Engines\Semantic;

use Laravel\Scout\Exceptions\ScoutException;

/**
 * Reads and validates the embedding settings of a model (the `typesenseEmbeddingSettings()`
 * method, or `scout.typesense.model-settings.{Model}.embedding`).
 */
final class EmbeddingSettings
{
    /**
     * @param array<string, mixed> $config The `scout.typesense` configuration.
     */
    public function __construct(private readonly array $config = [])
    {
    }

    /**
     * Get the raw embedding settings for a model.
     *
     * A model-level typesenseEmbeddingSettings() method takes precedence over
     * `scout.typesense.model-settings.{Model}.embedding` (Scout's format).
     *
     * @param mixed $model
     *
     * @return mixed
     */
    public function raw($model): mixed
    {
        if (method_exists($model, 'typesenseEmbeddingSettings')
            && ($settings = $model->typesenseEmbeddingSettings()) !== null) {
            return $settings;
        }

        return $this->config['model-settings'][get_class($model)]['embedding'] ?? null;
    }

    /**
     * @param mixed $model
     *
     * @return bool
     */
    public function has($model): bool
    {
        return !empty($this->raw($model));
    }

    /**
     * Get the validated embedding settings for a model.
     *
     * @param mixed $model
     *
     * @return array
     */
    public function settings($model): array
    {
        $settings = $this->raw($model);

        if (!is_array($settings) || $settings === []) {
            throw new ScoutException('No Typesense embedding settings have been configured for [' . get_class($model) . '].');
        }

        if (!isset($settings['attribute']) || !is_string($settings['attribute']) || trim($settings['attribute']) === '') {
            throw new ScoutException('Typesense embedding settings must contain an [attribute].');
        }

        $driver = $settings['driver'] ?? 'laravel-ai';

        if (!in_array($driver, ['laravel-ai', 'typesense'], true)) {
            throw new ScoutException("The [{$driver}] Typesense embedding driver is not supported.");
        }

        $settings['driver'] = $driver;

        if ($this->usesNative($settings)) {
            return $settings;
        }

        if (!isset($settings['dimensions'])
            || filter_var($settings['dimensions'], FILTER_VALIDATE_INT) === false
            || $settings['dimensions'] < 1) {
            throw new ScoutException('Typesense embedding settings must contain positive [dimensions].');
        }

        $settings['dimensions'] = (int) $settings['dimensions'];

        return $settings;
    }

    /**
     * Whether Typesense generates the embeddings itself (an `embed` field).
     *
     * @param array $settings
     *
     * @return bool
     */
    public function usesNative(array $settings): bool
    {
        return ($settings['driver'] ?? null) === 'typesense';
    }
}
