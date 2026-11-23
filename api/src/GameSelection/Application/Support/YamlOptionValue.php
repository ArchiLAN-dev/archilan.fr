<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Story 56.7: the value a player's YAML gives a numeric option, and the same YAML with another value.
 *
 * A weighted option reads as its likeliest value; `random` or `default` as the option's default. A changed YAML keeps
 * every other setting, empty mappings included (`{}` stays a mapping), since it is sent to the generator as is.
 */
final class YamlOptionValue
{
    public static function current(string $yaml, string $gameName, string $key, int $min, int $max, ?int $default): ?int
    {
        try {
            $doc = Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }
        $section = is_array($doc) && is_array($doc[$gameName] ?? null) ? $doc[$gameName] : null;
        if (null === $section || !array_key_exists($key, $section)) {
            return null === $default ? null : max($min, min($max, $default));
        }
        $value = self::likeliest($section[$key]);
        $value ??= $default;

        return null === $value ? null : max($min, min($max, $value));
    }

    /** The YAML with the option set to a single value; null when the YAML or its game section cannot be read. */
    public static function with(string $yaml, string $gameName, string $key, int $value): ?string
    {
        try {
            $doc = Yaml::parse($yaml, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException) {
            return null;
        }
        if (!$doc instanceof \stdClass || !isset($doc->{$gameName}) || !$doc->{$gameName} instanceof \stdClass) {
            return null;
        }
        $doc->{$gameName}->{$key} = $value;

        return Yaml::dump($doc, 10, 2, Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
    }

    private static function likeliest(mixed $node): ?int
    {
        if (is_int($node)) {
            return $node;
        }
        if (is_string($node)) {
            return is_numeric($node) ? (int) $node : null;
        }
        if (!is_array($node)) {
            return null;
        }
        $best = null;
        $bestWeight = 0;
        foreach ($node as $candidate => $weight) {
            if (is_int($weight) && $weight > $bestWeight) {
                $best = $candidate;
                $bestWeight = $weight;
            }
        }

        return null === $best ? null : self::likeliest($best);
    }
}
