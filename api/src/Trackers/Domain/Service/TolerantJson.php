<?php

declare(strict_types=1);

namespace App\Trackers\Domain\Service;

/**
 * Reads the JSON a PopTracker pack writes (story 54.1). PopTracker accepts more than strict JSON: a UTF-8 BOM,
 * `//` and block comments, and trailing commas. All three are removed outside strings, then the text is decoded.
 */
final readonly class TolerantJson
{
    private const string BOM = "\xEF\xBB\xBF";

    /**
     * @return array<mixed> the decoded object or array
     *
     * @throws \DomainException tracker_pack_manifest_invalid when the text is not a JSON object or array
     */
    public static function decode(string $text): array
    {
        if (str_starts_with($text, self::BOM)) {
            $text = substr($text, strlen(self::BOM));
        }

        try {
            $decoded = json_decode(self::withoutTrailingCommas(self::withoutComments($text)), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \DomainException('tracker_pack_manifest_invalid');
        }
        if (!is_array($decoded)) {
            throw new \DomainException('tracker_pack_manifest_invalid');
        }

        return $decoded;
    }

    private static function withoutComments(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $inString = false;
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ($inString) {
                $out .= $char;
                if ('\\' === $char && $i + 1 < $length) {
                    $out .= $text[++$i];
                } elseif ('"' === $char) {
                    $inString = false;
                }
                continue;
            }
            $next = $i + 1 < $length ? $text[$i + 1] : '';
            if ('/' === $char && '/' === $next) {
                $end = strpos($text, "\n", $i);
                $i = false === $end ? $length : $end - 1;
                continue;
            }
            if ('/' === $char && '*' === $next) {
                $end = strpos($text, '*/', $i + 2);
                $i = false === $end ? $length : $end + 1;
                continue;
            }
            if ('"' === $char) {
                $inString = true;
            }
            $out .= $char;
        }

        return $out;
    }

    private static function withoutTrailingCommas(string $text): string
    {
        $out = '';
        $length = strlen($text);
        $inString = false;
        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];
            if ($inString) {
                $out .= $char;
                if ('\\' === $char && $i + 1 < $length) {
                    $out .= $text[++$i];
                } elseif ('"' === $char) {
                    $inString = false;
                }
                continue;
            }
            if ('"' === $char) {
                $inString = true;
            } elseif (',' === $char) {
                $j = $i + 1;
                while ($j < $length && ctype_space($text[$j])) {
                    ++$j;
                }
                if ($j < $length && ('}' === $text[$j] || ']' === $text[$j])) {
                    continue;
                }
            }
            $out .= $char;
        }

        return $out;
    }
}
