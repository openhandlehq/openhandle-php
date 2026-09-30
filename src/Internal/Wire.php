<?php

declare(strict_types=1);

namespace OpenHandle\Internal;

use Closure;
use UnexpectedValueException;

/**
 * Typed readers for decoded response values. Generated models use them so a
 * response that breaks the contract fails with the offending field path.
 *
 * @internal
 */
final class Wire
{
    public static function string(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw self::invalid($path, 'a string');
        }

        return $value;
    }

    public static function nullableString(mixed $value, string $path): ?string
    {
        return $value === null ? null : self::string($value, $path);
    }

    public static function int(mixed $value, string $path): int
    {
        if (!is_int($value)) {
            throw self::invalid($path, 'an integer');
        }

        return $value;
    }

    public static function nullableInt(mixed $value, string $path): ?int
    {
        return $value === null ? null : self::int($value, $path);
    }

    public static function float(mixed $value, string $path): float
    {
        if (!is_int($value) && !is_float($value)) {
            throw self::invalid($path, 'a number');
        }

        return (float) $value;
    }

    public static function nullableFloat(mixed $value, string $path): ?float
    {
        return $value === null ? null : self::float($value, $path);
    }

    public static function bool(mixed $value, string $path): bool
    {
        if (!is_bool($value)) {
            throw self::invalid($path, 'a boolean');
        }

        return $value;
    }

    public static function nullableBool(mixed $value, string $path): ?bool
    {
        return $value === null ? null : self::bool($value, $path);
    }

    /**
     * @template T of string|int|bool
     *
     * @param list<T> $values
     *
     * @return T
     */
    public static function enum(mixed $value, string $path, array $values): string|int|bool
    {
        foreach ($values as $candidate) {
            if ($value === $candidate) {
                return $candidate;
            }
        }

        throw self::invalid($path, 'one of ' . implode(', ', array_map(static fn(string|int|bool $candidate): string => var_export($candidate, true), $values)));
    }

    /**
     * @template T of string|int|bool
     *
     * @param list<T> $values
     *
     * @return T|null
     */
    public static function nullableEnum(mixed $value, string $path, array $values): string|int|bool|null
    {
        return $value === null ? null : self::enum($value, $path, $values);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function object(mixed $value, string $path, string $class): Model
    {
        return $class::fromArray(self::map($value, $path), $path);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function nullableObject(mixed $value, string $path, string $class): ?Model
    {
        return $value === null ? null : self::object($value, $path, $class);
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value, string $path): array
    {
        if (!is_array($value) || (array_is_list($value) && $value !== [])) {
            throw self::invalid($path, 'an object');
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function nullableMap(mixed $value, string $path): ?array
    {
        return $value === null ? null : self::map($value, $path);
    }

    /**
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return array<string, T>
     */
    public static function mapOf(mixed $value, string $path, Closure $item): array
    {
        $result = [];
        foreach (self::map($value, $path) as $key => $entry) {
            $result[$key] = $item($entry, "{$path}.{$key}");
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return array<string, T>|null
     */
    public static function nullableMapOf(mixed $value, string $path, Closure $item): ?array
    {
        return $value === null ? null : self::mapOf($value, $path, $item);
    }

    /**
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return list<T>
     */
    public static function list(mixed $value, string $path, Closure $item): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw self::invalid($path, 'an array');
        }

        $result = [];
        foreach ($value as $index => $entry) {
            $result[] = $item($entry, "{$path}[{$index}]");
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param Closure(mixed, string): T $item
     *
     * @return list<T>|null
     */
    public static function nullableList(mixed $value, string $path, Closure $item): ?array
    {
        return $value === null ? null : self::list($value, $path, $item);
    }

    /**
     * Pick the model for an object that several models share. A URL marker
     * decides first, because the API omits empty fields. Otherwise the model
     * whose distinguishing fields are most present wins.
     *
     * @template T of Model
     *
     * @param array<string, mixed> $fields
     * @param non-empty-array<class-string<T>, list<string>> $candidates
     * @param array<class-string<T>, string> $urlMarkers
     *
     * @return class-string<T>
     */
    public static function variant(array $fields, array $candidates, array $urlMarkers = []): string
    {
        $url = $fields['url'] ?? null;
        foreach ($urlMarkers as $class => $marker) {
            if (is_string($url) && str_contains($url, $marker)) {
                return $class;
            }
        }
        $best = array_key_first($candidates);
        $bestScore = -1;
        foreach ($candidates as $class => $keys) {
            $score = count(array_intersect($keys, array_keys($fields)));
            if ($score > $bestScore) {
                $best = $class;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public static function invalid(string $path, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException("Response field {$path} must be {$expected}.");
    }
}
