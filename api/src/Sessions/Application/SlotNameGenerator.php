<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Shared\Domain\SlotName;

final class SlotNameGenerator
{
    /**
     * Generates slot names from player + game abbreviation pairs.
     *
     * A usable literal custom name (the player's YAML `name:`, passed as `preferredName`) wins;
     * otherwise the name is derived as {sanitizedPlayerName}_{gameAbbr} (max 16 chars).
     * Collision resolution: when two slots share the same base, all colliding
     * entries receive a numeric suffix (Alice_HK1, Alice_HK2, …) - applied to custom and
     * derived names alike so a session never has two identical slot names.
     *
     * @param list<array{playerName: string, archipelagoGameName: string, preferredName?: string|null}> $slots
     *
     * @return list<string>
     */
    public function generate(array $slots): array
    {
        // Pass 1 - compute base name for every slot
        $bases = [];
        foreach ($slots as $slot) {
            $preferred = $slot['preferredName'] ?? null;
            if (null !== $preferred && $this->isUsableCustomName($preferred)) {
                $bases[] = $preferred;
                continue;
            }

            $abbr = $this->abbreviate($slot['archipelagoGameName']);
            $player = $this->sanitize($slot['playerName']);
            $base = $player.'_'.$abbr;
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
                $result[] = mb_substr($base, 0, $maxLen).$suffix;
            }
        }

        return $result;
    }

    /**
     * A player-chosen name is honored only when it is a valid literal slot name with no AP
     * placeholder ({number}/{player}). Placeholder names - including the unconfigured default
     * `Player{number}` - resolve AP-side to a different literal than the stored string, which would
     * desync the patch-file lookup key (keyed on SessionSlot.slotName), so they fall back to the
     * derived {pseudo}_{abbr}.
     */
    private function isUsableCustomName(string $name): bool
    {
        return !str_contains($name, '{') && SlotName::isValid($name);
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
        // Keep letters, digits and underscore (drop apostrophes, accents, spaces, ...). Underscore is
        // allowed so a player's chosen `My_Name` survives instead of collapsing to `MyName`.
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';

        return '' !== $clean ? $clean : 'Player';
    }
}
