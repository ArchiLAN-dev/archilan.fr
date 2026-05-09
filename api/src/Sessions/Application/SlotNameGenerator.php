<?php

declare(strict_types=1);

namespace App\Sessions\Application;

final class SlotNameGenerator
{
    /**
     * Generates slot names from player + game abbreviation pairs.
     *
     * Format: {sanitizedPlayerName}_{gameAbbr} (max 16 chars).
     * Collision resolution: when two slots share the same base, all colliding
     * entries receive a numeric suffix (Alice_HK1, Alice_HK2, …).
     *
     * @param list<array{playerName: string, archipelagoGameName: string}> $slots
     *
     * @return list<string>
     */
    public function generate(array $slots): array
    {
        // Pass 1 - compute base name for every slot
        $bases = [];
        foreach ($slots as $slot) {
            $abbr = $this->abbreviate($slot['archipelagoGameName']);
            $player = $this->sanitize($slot['playerName']);
            $base = $player . '_' . $abbr;
            if (mb_strlen($base) > 16) {
                $base = mb_substr($base, 0, 16);
            }
            $bases[] = $base;
        }

        // Pass 2 - count occurrences to detect collisions
        $counts = array_count_values($bases);

        // Pass 3 - assign final names with collision suffixes where needed
        $counters = [];
        $result = [];
        foreach ($bases as $base) {
            if (1 === $counts[$base]) {
                $result[] = $base;
            } else {
                $counters[$base] = ($counters[$base] ?? 0) + 1;
                $suffix = (string) $counters[$base];
                $maxLen = 16 - mb_strlen($suffix);
                $result[] = mb_substr($base, 0, $maxLen) . $suffix;
            }
        }

        return $result;
    }

    private function abbreviate(string $gameName): string
    {
        if ('' === trim($gameName)) {
            return 'UNK';
        }

        /** @var list<string> $words */
        $words = (array) preg_split('/\s+/', trim($gameName), -1, PREG_SPLIT_NO_EMPTY);
        $abbr = '';
        foreach ($words as $word) {
            $abbr .= mb_strtoupper(mb_substr($word, 0, 1));
            if (mb_strlen($abbr) >= 3) {
                break;
            }
        }

        return '' !== $abbr ? $abbr : 'UNK';
    }

    private function sanitize(string $name): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $name) ?? '';

        return '' !== $clean ? $clean : 'Player';
    }
}
