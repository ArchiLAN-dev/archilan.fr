<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

/**
 * Story 30.57: filters the names of one game on what the admin started typing. Case and accents are ignored and
 * every word typed must appear (« water morpha » finds `Water Temple Morpha Heart`). Order: the name starts with
 * the text, then one of its words does, then the rest; alphabetical inside each. The names already picked are left
 * out.
 */
final class CounterNameSearch
{
    public const int MIN_QUERY = 2;

    public const int LIMIT = 30;

    private const array ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i', 'ñ' => 'n',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ÿ' => 'y', 'ý' => 'y',
        'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
    ];

    /**
     * @param list<string> $names
     * @param list<string> $exclude
     *
     * @return list<string>
     */
    public static function search(array $names, string $query, array $exclude = [], int $limit = self::LIMIT): array
    {
        $needle = self::normalize($query);
        if (mb_strlen($needle) < self::MIN_QUERY) {
            return [];
        }
        $words = array_values(array_filter(explode(' ', $needle), static fn (string $word): bool => '' !== $word));

        $ranked = [];
        foreach (array_unique($names) as $name) {
            if (\in_array($name, $exclude, true)) {
                continue;
            }
            $haystack = self::normalize($name);
            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    continue 2;
                }
            }
            $ranked[] = ['name' => $name, 'rank' => self::rank($haystack, $needle, $words[0] ?? $needle), 'key' => $haystack];
        }

        usort($ranked, static fn (array $a, array $b): int => [$a['rank'], $a['key'], $a['name']] <=> [$b['rank'], $b['key'], $b['name']]);

        return array_slice(array_map(static fn (array $entry): string => $entry['name'], $ranked), 0, max(0, $limit));
    }

    /** Lower case, no accents, single spaces. */
    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::ACCENTS);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function rank(string $haystack, string $needle, string $firstWord): int
    {
        if (str_starts_with($haystack, $needle)) {
            return 0;
        }
        if (1 === preg_match('/(^|[^a-z0-9])'.preg_quote($firstWord, '/').'/', $haystack)) {
            return 1;
        }

        return 2;
    }
}
