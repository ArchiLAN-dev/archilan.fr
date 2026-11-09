<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

use App\Sessions\Domain\ValueObject\PlaythroughStep;

/**
 * Story 52.7: reads the « Playthrough » of an Archipelago spoiler - the progression needed to finish, sphere by
 * sphere, each line `  Location (Finder): Item (Receiver)`.
 *
 * Names may hold spaces, colons or parentheses, so a line is cut on the players' names, which the caller knows,
 * rather than on punctuation. Sphere 0 (the starting inventory) has no location and is skipped. Null when the spoiler
 * has no playthrough (generated below spoiler level 2).
 */
final class SpoilerPlaythrough
{
    /**
     * @param list<string> $playerNames
     *
     * @return list<PlaythroughStep>|null
     */
    public static function parse(string $spoiler, array $playerNames): ?array
    {
        $lines = preg_split('/\R/', $spoiler) ?: [];
        $start = array_search('Playthrough:', array_map(trim(...), $lines), true);
        if (false === $start) {
            return null;
        }
        // Longest first: « Bob » must not win over « Bob Le Grand ».
        usort($playerNames, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        $steps = [];
        $sphere = null;
        $count = count($lines);
        for ($i = $start + 1; $i < $count; ++$i) {
            $line = $lines[$i];
            if (1 === preg_match('/^(\d+): \{$/', $line, $m)) {
                $sphere = (int) $m[1];
                continue;
            }
            if ('}' === $line) {
                $sphere = null;
                continue;
            }
            if (null === $sphere) {
                // Past the blocks: the next section starts.
                if ('' !== trim($line) && [] !== $steps) {
                    break;
                }
                continue;
            }
            if (0 === $sphere) {
                continue;
            }
            $step = self::step($sphere, trim($line), $playerNames);
            if (null !== $step) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    /**
     * @param list<string> $names longest first
     */
    private static function step(int $sphere, string $line, array $names): ?PlaythroughStep
    {
        foreach ($names as $receiver) {
            $suffix = ' ('.$receiver.')';
            if (!str_ends_with($line, $suffix)) {
                continue;
            }
            $rest = substr($line, 0, -strlen($suffix));
            $best = null;
            foreach ($names as $finder) {
                $position = strpos($rest, ' ('.$finder.'): ');
                if (false !== $position && (null === $best || $position < $best[0])) {
                    $best = [$position, $finder];
                }
            }
            if (null === $best) {
                return null;
            }
            [$position, $finder] = $best;

            return new PlaythroughStep(
                $sphere,
                $finder,
                substr($rest, 0, $position),
                $receiver,
                substr($rest, $position + strlen(' ('.$finder.'): ')),
            );
        }

        return null;
    }
}
