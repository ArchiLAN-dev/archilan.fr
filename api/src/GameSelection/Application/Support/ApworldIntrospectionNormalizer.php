<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

/**
 * Validates what the orchestrator's introspection says about an apworld - option types and location
 * names - before it is stored on a game.
 *
 * Moved out of AdminGameLibrary by story 38.6, unchanged: it used to run only when an upload switched
 * the game at once. The switch now happens on promotion (PromoteApworldCandidate), which must apply the
 * same rules - in particular the story 9.51 rule that drops a half-known dict vocabulary.
 */
final class ApworldIntrospectionNormalizer
{
    /**
     * @return list<string>
     */
    public static function locationNames(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $name) {
            if (is_string($name) && '' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Validate the apworld's option-type table at the boundary (story 9.33).
     *
     * It used to keep only entries carrying integer bounds, which is how every non-range option was
     * lost on the way to the editor. It now keeps whatever type the apworld declared, and still
     * refuses anything it cannot name - an option with neither a type nor bounds says nothing.
     *
     * @return array<string, array{type: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>
     */
    public static function optionTypes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $types = [];
        foreach ($raw as $key => $spec) {
            if (!is_string($key) || !is_array($spec)) {
                continue;
            }

            $min = $spec['min'] ?? null;
            $max = $spec['max'] ?? null;
            $hasBounds = is_int($min) && is_int($max);

            $declared = $spec['type'] ?? null;
            // A row written before story 9.33 has bounds and no type: it could only ever have been a
            // range, since that was the single type this method let through.
            $type = is_string($declared) && '' !== $declared ? $declared : ($hasBounds ? 'range' : null);
            if (null === $type) {
                continue;
            }

            $entry = ['type' => $type];
            if ($hasBounds) {
                $entry['min'] = $min;
                $entry['max'] = $max;
            }

            $default = $spec['default'] ?? null;
            if (is_int($default) || is_string($default) || is_bool($default) || null === $default) {
                $entry['default'] = $default;
            }

            $values = $spec['values'] ?? null;
            if (is_array($values)) {
                $entry['values'] = array_values(array_filter($values, is_string(...)));
            }

            $subOptions = self::dictSubOptions($spec['keys'] ?? null);
            if ([] !== $subOptions) {
                $entry['keys'] = $subOptions;
            }

            $types[$key] = $entry;
        }

        return $types;
    }

    /**
     * What each sub-setting of a dict option accepts, when the apworld declared it (story 9.51).
     *
     * A sub-setting left with fewer than two values is dropped rather than stored. Half a vocabulary
     * is the worst thing a dropdown can be given: it reads as authoritative while hiding the entries
     * the world actually accepts, and the player cannot see what is missing.
     *
     * @return array<string, array{values: list<string>}>
     */
    private static function dictSubOptions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $subOptions = [];
        foreach ($raw as $subKey => $sub) {
            if (!is_string($subKey) || !is_array($sub)) {
                continue;
            }

            $values = $sub['values'] ?? null;
            if (!is_array($values)) {
                continue;
            }

            $clean = array_values(array_unique(array_filter($values, is_string(...))));
            if (count($clean) > 1) {
                $subOptions[$subKey] = ['values' => $clean];
            }
        }

        return $subOptions;
    }
}
