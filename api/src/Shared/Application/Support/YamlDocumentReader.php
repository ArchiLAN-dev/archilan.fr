<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a player's or a game's YAML document the one way the apworld health does (story 38.7 review):
 * a byte order mark is dropped, and anything that is not a mapping - unreadable, empty, a bare
 * sentence - reads as null. The slot upgrade (38.7) and the default-YAML failure check (38.4) must see
 * a document the same way, or one would call "unreadable" what the other compares.
 */
final class YamlDocumentReader
{
    /**
     * @return array<mixed>|null null when absent, unreadable or not a mapping
     */
    public static function read(?string $yaml): ?array
    {
        if (null === $yaml) {
            return null;
        }
        if (str_starts_with($yaml, "\u{FEFF}")) {
            $yaml = substr($yaml, 3);
        }
        if ('' === trim($yaml)) {
            return null;
        }

        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException) {
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }
}
