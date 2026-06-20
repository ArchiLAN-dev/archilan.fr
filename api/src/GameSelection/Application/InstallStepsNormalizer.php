<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\InstallStepType;

/**
 * Validates and normalizes raw install-tutorial steps coming off a request body (story 31.1).
 * Shared by admin authoring and, later, community submission (31.6) so the rules - including the
 * security-relevant ones - live in one place:
 *  - `type` must be a known {@see InstallStepType};
 *  - `title` is required;
 *  - `description` is plain text (never HTML - it is rendered safely downstream);
 *  - link `url` must be http/https (or null); other schemes (e.g. `javascript:`) are dropped.
 * Over-long fields are truncated. Returns the clean list plus any collected errors.
 */
final readonly class InstallStepsNormalizer
{
    public const MAX_TITLE = 200;
    public const MAX_DESCRIPTION = 2000;
    public const MAX_LABEL = 200;
    public const MAX_IMAGE_KEY = 256;

    /**
     * @param array<mixed> $rawSteps
     *
     * @return array{steps: list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>, imageKey: string|null, imageUrl: string|null, videoUrl: string|null}>, errors: list<string>}
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
                'links' => $this->normalizeLinks($raw['links'] ?? null, $index, $errors),
                'imageKey' => self::optionalImageKey($raw['imageKey'] ?? null),
                'imageUrl' => self::optionalUrl($raw['imageUrl'] ?? null),
                'videoUrl' => self::optionalUrl($raw['videoUrl'] ?? null),
            ];
        }

        return ['steps' => $steps, 'errors' => $errors];
    }

    /**
     * @param list<string> $errors
     *
     * @return list<array{label: string, url: string|null}>
     */
    private function normalizeLinks(mixed $rawLinks, int $stepIndex, array &$errors): array
    {
        if (!is_array($rawLinks)) {
            return [];
        }

        $links = [];

        foreach ($rawLinks as $rawLink) {
            if (!is_array($rawLink)) {
                continue;
            }

            $label = is_string($rawLink['label'] ?? null) ? trim($rawLink['label']) : '';
            if ('' === $label) {
                continue;
            }

            $url = null;
            $rawUrl = $rawLink['url'] ?? null;
            if (is_string($rawUrl) && '' !== trim($rawUrl)) {
                $url = self::normalizeUrl(trim($rawUrl));
                if (null === $url) {
                    $errors[] = sprintf('Étape %d : lien "%s" - seules les URL http(s) sont autorisées.', $stepIndex, $label);
                    continue;
                }
            }

            $links[] = ['label' => mb_substr($label, 0, self::MAX_LABEL), 'url' => $url];
        }

        return $links;
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

    /**
     * Uploaded image reference: an opaque MinIO object key under the `tutorials/` prefix (story 31.10).
     * Anything else (empty, wrong prefix, over-long) is dropped to null so a hand-crafted body can't make
     * the read side presign an arbitrary object.
     */
    private static function optionalImageKey(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $key = trim($raw);

        return '' !== $key && str_starts_with($key, 'tutorials/') && mb_strlen($key) <= self::MAX_IMAGE_KEY ? $key : null;
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
