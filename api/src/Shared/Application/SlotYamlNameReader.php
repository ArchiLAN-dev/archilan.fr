<?php

declare(strict_types=1);

namespace App\Shared\Application;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the slot/player name (the YAML `name:` field) out of a raw player YAML string.
 *
 * Centralises the BOM-safe parse + `name:` lookup shared by the slot-save validation and the
 * session/personal-run launch pipelines, so they all agree on what the player typed. Returns null
 * when the YAML is absent, unparseable, or has no string `name:` (the caller then falls back).
 */
final class SlotYamlNameReader
{
    public static function read(string $playerYaml): ?string
    {
        // Strip a leading UTF-8 BOM: Symfony's YAML parser throws on it.
        if (str_starts_with($playerYaml, "\u{FEFF}")) {
            $playerYaml = substr($playerYaml, 3);
        }

        try {
            $parsed = Yaml::parse($playerYaml);
        } catch (ParseException) {
            return null;
        }

        if (!is_array($parsed) || !is_string($parsed['name'] ?? null)) {
            return null;
        }

        return $parsed['name'];
    }
}
