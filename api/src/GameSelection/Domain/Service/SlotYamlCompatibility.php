<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Service;

use App\GameSelection\Domain\Enum\SlotYamlCase;
use App\GameSelection\Domain\Enum\SlotYamlProblem;
use App\GameSelection\Domain\ValueObject\SlotYamlIssue;
use App\GameSelection\Domain\ValueObject\SlotYamlVerdict;

/**
 * What a game switching apworld does to a slot YAML of a run not yet launched (story 38.7).
 *
 * Jean's rule: never replace a YAML the player customised as long as it still holds; warn them when
 * it no longer does. So:
 * 1. empty, or saying the same as the OLD default YAML: it becomes the NEW default;
 * 2. every setting the player wrote still exists in the new version with an accepted value: kept as is;
 * 3. otherwise kept as is too, but marked for review with the settings at fault.
 *
 * "Still exists" is judged against the new default YAML, which lists every option of the new version,
 * common Archipelago ones included - the introspected types may not. "Accepted" is judged against the
 * introspected types when they say something; an option of unknown type gets the benefit of the doubt.
 * Archipelago's random values (`random`, `random-low`, `random-range-3-10`...) are always accepted, and a
 * weight of 0 is never drawn, so it is never judged.
 *
 * Pure: works on YAML already parsed. A YAML that does not parse is the caller's to report
 * ({@see self::unreadable()}).
 */
final class SlotYamlCompatibility
{
    /**
     * @param array<mixed>|null    $playerYaml
     * @param array<mixed>         $oldDefaultYaml
     * @param array<mixed>         $newDefaultYaml
     * @param array<string, mixed> $newOptionTypes
     */
    public static function classify(?array $playerYaml, array $oldDefaultYaml, array $newDefaultYaml, array $newOptionTypes): SlotYamlVerdict
    {
        if (null === $playerYaml || [] === $playerYaml || DefaultYamlEquivalence::isEquivalent($playerYaml, $oldDefaultYaml)) {
            return new SlotYamlVerdict(SlotYamlCase::ReplaceWithDefault);
        }

        $game = $playerYaml['game'] ?? $newDefaultYaml['game'] ?? null;
        $section = is_string($game) ? ($playerYaml[$game] ?? null) : null;
        if (!is_array($section)) {
            // Nothing set for the game: Archipelago uses its defaults, whatever the version.
            return new SlotYamlVerdict(SlotYamlCase::Keep);
        }

        $newSection = is_array($newDefaultYaml[$game] ?? null) ? $newDefaultYaml[$game] : [];

        $issues = [];
        foreach ($section as $option => $value) {
            $option = (string) $option;
            if ([] !== $newSection && !\array_key_exists($option, $newSection)) {
                $issues[] = new SlotYamlIssue($option, SlotYamlProblem::RemovedOption, null);
                continue;
            }
            $type = $newOptionTypes[$option] ?? null;
            if (is_array($type)) {
                $issues = [...$issues, ...self::valueIssues($option, $value, $type)];
            }
        }

        return [] === $issues
            ? new SlotYamlVerdict(SlotYamlCase::Keep)
            : new SlotYamlVerdict(SlotYamlCase::NeedsReview, $issues);
    }

    public static function unreadable(): SlotYamlVerdict
    {
        return new SlotYamlVerdict(SlotYamlCase::NeedsReview, [new SlotYamlIssue('', SlotYamlProblem::Unreadable, null)]);
    }

    /**
     * @param array<mixed> $type
     *
     * @return list<SlotYamlIssue>
     */
    private static function valueIssues(string $option, mixed $value, array $type): array
    {
        return match ($type['type'] ?? null) {
            'choice' => self::choiceIssues($option, $value, self::stringList($type['values'] ?? null)),
            'range' => self::rangeIssues($option, $value, $type['min'] ?? null, $type['max'] ?? null),
            'dict' => self::dictIssues($option, $value, $type),
            // toggle, weights, text, or a type this code does not know: nothing to hold against it.
            default => [],
        };
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<SlotYamlIssue>
     */
    private static function choiceIssues(string $option, mixed $value, array $allowed): array
    {
        if ([] === $allowed) {
            return [];
        }
        $known = array_map(strtolower(...), $allowed);

        $issues = [];
        foreach (self::drawnValues($value) as $candidate) {
            // A number stands for a choice by its index in Archipelago: nothing to check it against.
            if (is_int($candidate) || self::isRandom($candidate) || \in_array(strtolower(self::text($candidate)), $known, true)) {
                continue;
            }
            $issues[] = new SlotYamlIssue($option, SlotYamlProblem::UnknownValue, self::text($candidate));
        }

        return $issues;
    }

    /**
     * @return list<SlotYamlIssue>
     */
    private static function rangeIssues(string $option, mixed $value, mixed $min, mixed $max): array
    {
        if (!is_int($min) || !is_int($max)) {
            return [];
        }

        $issues = [];
        foreach (self::drawnValues($value) as $candidate) {
            // A named value ("normal", "extreme") is the world's to resolve: only numbers are checked.
            if (!is_int($candidate) && !(is_string($candidate) && ctype_digit($candidate))) {
                continue;
            }
            $number = (int) $candidate;
            if ($number < $min || $number > $max) {
                $issues[] = new SlotYamlIssue($option, SlotYamlProblem::OutOfRange, (string) $number);
            }
        }

        return $issues;
    }

    /**
     * @param array<mixed> $type
     *
     * @return list<SlotYamlIssue>
     */
    private static function dictIssues(string $option, mixed $value, array $type): array
    {
        if (!is_array($value) || array_is_list($value)) {
            return [];
        }
        $knownKeys = self::stringList($type['values'] ?? null);
        $valuesByKey = is_array($type['keys'] ?? null) ? $type['keys'] : [];

        $issues = [];
        foreach ($value as $subKey => $subValue) {
            $subKey = (string) $subKey;
            if ([] !== $knownKeys && !\in_array($subKey, $knownKeys, true)) {
                $issues[] = new SlotYamlIssue($option.'.'.$subKey, SlotYamlProblem::UnknownSubOption, null);
                continue;
            }
            $declared = $valuesByKey[$subKey] ?? null;
            $allowed = is_array($declared) ? self::stringList($declared['values'] ?? null) : [];
            if ([] !== $allowed && is_scalar($subValue) && !\in_array(self::text($subValue), $allowed, true)) {
                $issues[] = new SlotYamlIssue($option.'.'.$subKey, SlotYamlProblem::UnknownValue, self::text($subValue));
            }
        }

        return $issues;
    }

    /**
     * The values the generator may draw: a plain value, or the choices of a weight map whose weight
     * is above 0.
     *
     * @return list<int|string|bool>
     */
    private static function drawnValues(mixed $value): array
    {
        if (is_array($value)) {
            $drawn = [];
            foreach ($value as $choice => $weight) {
                if (is_int($weight) && $weight > 0) {
                    $drawn[] = $choice;
                }
            }

            return $drawn;
        }

        return is_int($value) || is_string($value) || is_bool($value) ? [$value] : [];
    }

    private static function isRandom(int|string|bool $value): bool
    {
        return is_string($value) && str_starts_with(strtolower($value), 'random');
    }

    private static function text(int|string|bool|float $value): string
    {
        return match (true) {
            true === $value => 'true',
            false === $value => 'false',
            default => (string) $value,
        };
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $values): array
    {
        return is_array($values) ? array_values(array_filter($values, is_string(...))) : [];
    }
}
