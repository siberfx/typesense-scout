<?php

namespace Siberfx\Typesense\Engines\Filters;

use Laravel\Scout\Builder;

/**
 * Builds the Typesense `filter_by` string from the where / whereIn / whereNotIn
 * clauses of a Scout builder.
 */
final class FilterBuilder
{
    public function __construct(private readonly bool $escape = false)
    {
    }

    /**
     * Prepare filters.
     *
     * @param Builder $builder
     *
     * @return string
     */
    public function build(Builder $builder): string
    {
        $escape = $this->escape;

        // Operator arrays such as ['>', 100] are filter syntax, never escaped.
        $whereValue = fn ($value) => $escape && !is_array($value)
            ? $this->escapeWhereValue($value)
            : $this->parseFilterValue($value);

        $listValue = fn (array $values) => $escape
            ? array_map([$this, 'escapeWhereValue'], $values)
            : array_map([$this, 'quoteListValue'], $this->parseFilterValue($values));

        // Scout 11 stores where() clauses as ['field', 'operator', 'value']
        // entries; plain `field => value` pairs are still accepted.
        $whereFilter = collect($builder->wheres)
            ->map(fn ($where, $key) => is_array($where) && array_key_exists('field', $where) && array_key_exists('value', $where)
                ? $this->parseWhereFilter($whereValue($where['value']), $where['field'], $where['operator'] ?? '=')
                : $this->parseWhereFilter($whereValue($where), $key))
            ->values()
            ->implode(' && ');

        $whereInFilter = collect($builder->whereIns)
            ->map(fn ($value, $key) => $this->parseWhereInFilter($listValue($value), $key))
            ->values()
            ->implode(' && ');

        $whereNotInFilter = collect($builder->whereNotIns)
            ->map(fn ($value, $key) => $this->parseWhereNotInFilter($listValue($value), $key))
            ->values()
            ->implode(' && ');

        return collect([$whereFilter, $whereInFilter, $whereNotInFilter])
            ->filter(static fn (string $filter): bool => $filter !== '')
            ->implode(' && ');
    }

    /**
     * Normalise a filter value before it is rendered into a Typesense filter
     * string. Booleans become Typesense's literal `true`/`false`, and arrays
     * are normalised recursively (so operator arrays such as ['>', 100] and
     * whereIn/whereNotIn value lists are handled consistently).
     *
     * @param array|string|bool|int|float $value
     *
     * @return array|string|int|float
     */
    public function parseFilterValue(array|string|bool|int|float $value): array|string|int|float
    {
        if (is_array($value)) {
            return array_map([$this, 'parseFilterValue'], $value);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }

    /**
     * Escape a value for safe use in a Typesense `filter_by` string.
     *
     * Strings are wrapped in backticks (inner backticks escaped), so values
     * containing `&&`, `||`, `,`, `[`, `]` or `:` cannot alter the filter.
     * Same behaviour as \Typesense\FilterBy::escape() in typesense-php 6.1.
     *
     * @param string|int|float|bool $value
     *
     * @return string
     */
    public static function escapeFilterValue(string|int|float|bool $value): string
    {
        return match (true) {
            is_string($value) => '`' . str_replace('`', '\\`', $value) . '`',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    /**
     * Quote a whereIn/whereNotIn list item when it would otherwise break the list.
     *
     * Plain values (words, numbers, booleans) stay unquoted, so the common output
     * is unchanged. A string with a comma, space, bracket or backtick, or an empty
     * string, is quoted, because `[a,b]` is read as two items.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function quoteListValue(mixed $value): mixed
    {
        if (is_string($value) && !is_numeric($value)
            && ($value === '' || preg_match('/[^\w.\-@\/]/u', $value) === 1)) {
            return static::escapeFilterValue($value);
        }

        return $value;
    }

    /**
     * Escape a where/whereIn value when `scout.typesense.escape_filter_values`
     * is enabled. Numeric strings (typical of request input) stay unquoted so
     * they keep matching numeric fields.
     *
     * @param string|int|float|bool $value
     *
     * @return string
     */
    private function escapeWhereValue(string|int|float|bool $value): string
    {
        return is_string($value) && is_numeric($value) ? $value : static::escapeFilterValue($value);
    }

    /**
     * Parse typesense where filter.
     *
     * Passing an array enables comparison/range operators, e.g.
     * where('price', ['>', 100]) => "price:>100" and
     * where('price', ['[10..100]']) => "price:[10..100]".
     * Scout's own operator form works too: where('price', '>', 100).
     *
     * @param array|string $value
     * @param string $key
     * @param string $operator One of =, !=, <, >, <=, >=.
     *
     * @return string
     */
    public function parseWhereFilter(array|string $value, string $key, string $operator = '='): string
    {
        if (is_array($value)) {
            return sprintf('%s:%s', $key, implode('', $value));
        }

        $operator = match ($operator) {
            '!=', '<>' => ':!=',
            '<' => ':<',
            '>' => ':>',
            '<=' => ':<=',
            '>=' => ':>=',
            default => ':=',
        };

        return sprintf('%s%s%s', $key, $operator, $value);
    }

    /**
     * Parse typesense  whereIn filter.
     *
     * @param array $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereInFilter(array $value, string $key): string
    {
        return sprintf('%s:=%s', $key, '[' . implode(', ', $value) . ']');
    }

    /**
     * Parse typesense whereNotIn filter.
     *
     * @param array $value
     * @param string $key
     *
     * @return string
     */
    public function parseWhereNotInFilter(array $value, string $key): string
    {
        return sprintf('%s:!=%s', $key, '[' . implode(', ', $value) . ']');
    }
}
