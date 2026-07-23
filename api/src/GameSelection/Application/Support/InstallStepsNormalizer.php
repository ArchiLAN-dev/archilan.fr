<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Domain\Enum\InstallStepType;

/**
 * Validates and normalizes raw install-tutorial steps coming off a request body (story 31.1).
 * Shared by admin authoring and, later, community submission (31.6) so the rules - including the
 * security-relevant ones - live in one place:
 *  - `type` must be a known {@see InstallStepType};
 *  - `title` is required;
 *  - `description` is markdown since story 10.10 - still never HTML: the frontend renders it with
 *    react-markdown, which emits React elements, so any raw HTML in it stays inert text;
 *  - `videoUrl` must be http/https (or null); other schemes are dropped;
 *  - links and images now live inside the markdown description (story 31.11).
 * Over-long fields are truncated. Returns the clean list plus any collected errors.
 */
final readonly class InstallStepsNormalizer
{
    public const int MAX_TITLE = 200;
    public const int MAX_DESCRIPTION = 2000;

    /**
     * @param array<mixed> $rawSteps
     *
     * @return array{steps: list<array{type: string, title: string, description: string, videoUrl: string|null}>, errors: list<string>}
     */
    public function normalize(array $rawSteps): array
    {
        $steps = [];
        $errors = [];
        $index = 0;

        foreach ($rawSteps as $raw) {
            ++$index;

            if (!is_array($raw)) {
                $errors[] = sprintf('Étape %d : format invalide.', $index);
                continue;
            }

            $type = is_string($raw['type'] ?? null) ? trim($raw['type']) : '';
            $title = is_string($raw['title'] ?? null) ? trim($raw['title']) : '';
            $description = is_string($raw['description'] ?? null) ? trim($raw['description']) : '';

            if (!InstallStepType::isValid($type)) {
                $errors[] = sprintf('Étape %d : type d\'étape invalide.', $index);
                continue;
            }

            if ('' === $title) {
                $errors[] = sprintf('Étape %d : le titre est requis.', $index);
                continue;
            }

            $steps[] = [
                'type' => $type,
                'title' => mb_substr($title, 0, self::MAX_TITLE),
                'description' => mb_substr($description, 0, self::MAX_DESCRIPTION),
                'videoUrl' => self::optionalUrl($raw['videoUrl'] ?? null),
            ];
        }

        return ['steps' => $steps, 'errors' => $errors];
    }

    /**
     * Returns a safe http(s) URL, assuming https for a bare host/path (e.g. "example.org/x"),
     * or null when the URL carries a non-http scheme (e.g. "javascript:", "ftp:").
     */
    /**
     * Optional media URL (image/video): null when absent/empty/unsafe; otherwise a safe http(s) URL
     * (https assumed when the scheme is missing). Invalid schemes are dropped silently (no error).
     */
    private static function optionalUrl(mixed $raw): ?string
    {
        if (!is_string($raw) || '' === trim($raw)) {
            return null;
        }

        return self::normalizeUrl(trim($raw));
    }

    private static function normalizeUrl(string $candidate): ?string
    {
        if (null === parse_url($candidate, PHP_URL_SCHEME)) {
            $candidate = 'https://'.ltrim($candidate, '/');
        }

        $scheme = parse_url($candidate, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) ? $candidate : null;
    }
}
