<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * Parses the stderr of a failed Archipelago generation (reported by the orchestrateur through
 * the `session.crashed` webhook) into per-slot findings, plus a log cleaned of known noise.
 *
 * Recognized patterns, in priority order (story 9.40):
 *  1. AutoWorld marker "Exception in <bound method ...> for player N, named {slot}." - the
 *     message is the last exception line of the traceback preceding the marker;
 *  2. player-file errors from Generate.py "N. File {slot}.yaml document #d (...) is invalid." -
 *     the yaml file stem is the slot name;
 *  3. fallback: the last "SomeError: ..." line of the log, unattributed.
 *
 * Stripped noise: `DEBUG ` lines and the "Warning: pip install failed" blocks (client-only
 * dependencies can never install in the sealed generation container - always benign).
 */
final class GenerationFailureParser
{
    private const string ENVELOPE_PATTERN = '/^generate_multiworld\.py exited \d+:\s*/';
    private const string EXCEPTION_LINE_PATTERN = '/^(?:[A-Za-z_][A-Za-z0-9_.]*)?(?:Error|Exception): .+$/';
    /** Same, but also matches a header whose message is empty (multi-line message on the lines below). */
    private const string EXCEPTION_HEADER_PATTERN = '/^((?:[A-Za-z_][A-Za-z0-9_.]*)?(?:Error|Exception)):\s*(.*)$/';
    private const string PLAYER_MARKER_PATTERN = '/^Exception in .+ for player \d+, named (\S+)\.\s*$/';
    private const string PLAYER_FILE_PATTERN = '/^\d+\. File (\S+)\.yaml document #\d+ .*is invalid\./';

    /** Bounds for a message rebuilt from continuation lines (worlds print decorated blocks). */
    private const int MAX_CONTINUATION_LINES = 5;
    private const int MAX_MESSAGE_LENGTH = 500;

    private function __construct()
    {
    }

    public static function parse(?string $reason): GenerationFailureReport
    {
        $text = trim($reason ?? '');
        $text = preg_replace(self::ENVELOPE_PATTERN, '', $text) ?? $text;

        $lines = self::stripNoise($text);
        $cleanedLog = implode("\n", $lines);

        $findings = [...self::playerMarkerFindings($lines), ...self::playerFileFindings($lines)];

        if ([] === $findings) {
            $last = self::lastExceptionLine($lines);
            if (null !== $last) {
                $findings[] = new GenerationFailureFinding(null, $last);
            }
        }

        return new GenerationFailureReport(self::dedupe($findings), $cleanedLog);
    }

    /**
     * @return list<string>
     */
    private static function stripNoise(string $text): array
    {
        $kept = [];
        $inPipBlock = false;

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $line = rtrim($line);

            if (str_starts_with($line, 'Warning: pip install failed for ')) {
                $inPipBlock = true;
                continue;
            }
            // pip's own output lines are "ERROR: " (with a space) - distinct from the
            // meaningful "ERROR:root:" logger lines, which must survive.
            if ($inPipBlock && str_starts_with($line, 'ERROR: ')) {
                continue;
            }
            $inPipBlock = false;

            if (str_starts_with($line, 'DEBUG ')) {
                continue;
            }

            $kept[] = $line;
        }

        return $kept;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<GenerationFailureFinding>
     */
    private static function playerMarkerFindings(array $lines): array
    {
        $findings = [];
        $lastException = null;

        foreach ($lines as $index => $line) {
            if (1 === preg_match(self::PLAYER_MARKER_PATTERN, $line, $matches)) {
                $findings[] = new GenerationFailureFinding($matches[1], $lastException ?? $line);
                continue;
            }
            $message = self::exceptionMessageAt($lines, $index);
            if (null !== $message) {
                $lastException = $message;
            }
        }

        return $findings;
    }

    /**
     * Message of the exception whose header sits at $index, or null when that line is not a
     * header. A header with an inline message is returned as-is; a header with an empty
     * message (`OptionError:` - the world raised a multi-line, often decorated, block) is
     * completed with the following unindented lines, which is where the actionable text is.
     *
     * @param list<string> $lines
     */
    private static function exceptionMessageAt(array $lines, int $index): ?string
    {
        if (1 !== preg_match(self::EXCEPTION_HEADER_PATTERN, $lines[$index], $matches)) {
            return null;
        }

        $type = $matches[1];
        $inline = trim($matches[2]);
        if ('' !== $inline) {
            return $lines[$index];
        }

        $continuation = [];
        for ($ahead = $index + 1; $ahead < count($lines); ++$ahead) {
            $next = $lines[$ahead];

            if ('' === trim($next)) {
                // Blank lines before the message are part of the world's formatting; a blank
                // line after it means the message is over.
                if ([] === $continuation) {
                    continue;
                }
                break;
            }
            // A traceback frame, the AutoWorld marker, a numbered player-file entry or another
            // exception header all mean we left the message body.
            if ($next !== ltrim($next)
                || str_starts_with($next, 'Traceback')
                || 1 === preg_match(self::PLAYER_MARKER_PATTERN, $next)
                || 1 === preg_match(self::PLAYER_FILE_PATTERN, $next)
                || 1 === preg_match(self::EXCEPTION_HEADER_PATTERN, $next)) {
                break;
            }

            $continuation[] = trim($next);
            if (count($continuation) >= self::MAX_CONTINUATION_LINES) {
                break;
            }
        }

        if ([] === $continuation) {
            return null;
        }

        return mb_substr($type.': '.implode(' ', $continuation), 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * @param list<string> $lines
     *
     * @return list<GenerationFailureFinding>
     */
    private static function playerFileFindings(array $lines): array
    {
        $findings = [];

        foreach ($lines as $index => $line) {
            if (1 !== preg_match(self::PLAYER_FILE_PATTERN, $line, $matches)) {
                continue;
            }

            // The generator prints the exception right under the numbered "File ... is invalid"
            // entry; scan a few lines ahead, stopping at the next numbered entry.
            $message = 'Fichier YAML invalide.';
            for ($ahead = $index + 1; $ahead < min($index + 6, count($lines)); ++$ahead) {
                if (1 === preg_match('/^\d+\. File /', $lines[$ahead])) {
                    break;
                }
                if (1 === preg_match(self::EXCEPTION_LINE_PATTERN, $lines[$ahead])) {
                    $message = $lines[$ahead];
                    break;
                }
            }

            $findings[] = new GenerationFailureFinding($matches[1], $message);
        }

        return $findings;
    }

    /**
     * @param list<string> $lines
     */
    private static function lastExceptionLine(array $lines): ?string
    {
        $last = null;
        foreach (array_keys($lines) as $index) {
            $message = self::exceptionMessageAt($lines, $index);
            if (null !== $message) {
                $last = $message;
            }
        }

        return $last;
    }

    /**
     * @param list<GenerationFailureFinding> $findings
     *
     * @return list<GenerationFailureFinding>
     */
    private static function dedupe(array $findings): array
    {
        $seen = [];
        $unique = [];
        foreach ($findings as $finding) {
            $key = ($finding->slotName ?? "\0").'|'.$finding->message;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $finding;
        }

        return $unique;
    }
}
