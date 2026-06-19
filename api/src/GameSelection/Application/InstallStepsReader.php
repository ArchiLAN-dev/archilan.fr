<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\InstallStepType;
use App\Shared\Infrastructure\MinioStorageInterface;

/**
 * Read-side presenter for install-tutorial steps (story 31.10). Centralizes what used to be duplicated
 * across the game-catalog, guide and contribution queries: drop steps with an unknown type (the public
 * type guard is all-or-nothing), normalize links, and - the new part - resolve the displayed image.
 *
 * Images can be either an external pasted `imageUrl` or an uploaded `imageKey` (a private MinIO media
 * object). An uploaded key wins and is presigned to a short-lived URL at read time; the raw `imageKey`
 * is also carried through so the admin editor can round-trip it without persisting the expiring URL.
 */
final readonly class InstallStepsReader
{
    public function __construct(
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>, imageKey: string|null, imageUrl: string|null, videoUrl: string|null}>
     */
    public function presentJson(mixed $rawJson): array
    {
        if (!is_string($rawJson) || '' === $rawJson) {
            return [];
        }

        $decoded = json_decode($rawJson, true);

        return is_array($decoded) ? $this->present($decoded) : [];
    }

    /**
     * @param array<mixed> $rawSteps
     *
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>, imageKey: string|null, imageUrl: string|null, videoUrl: string|null}>
     */
    public function present(array $rawSteps): array
    {
        $steps = [];

        foreach ($rawSteps as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $type = $raw['type'] ?? null;
            $title = $raw['title'] ?? null;
            if (!is_string($type) || !is_string($title) || !InstallStepType::isValid($type)) {
                continue;
            }

            $description = $raw['description'] ?? null;
            $videoUrl = $raw['videoUrl'] ?? null;

            $rawImageKey = $raw['imageKey'] ?? null;
            $imageKey = is_string($rawImageKey) && '' !== $rawImageKey ? $rawImageKey : null;
            $rawImageUrl = $raw['imageUrl'] ?? null;
            $imageUrl = null !== $imageKey
                ? $this->minioStorage->presignedUrl($this->minioMediaBucket, $imageKey, $this->minioPresignTtl)
                : (is_string($rawImageUrl) ? $rawImageUrl : null);

            $steps[] = [
                'type' => $type,
                'title' => $title,
                'description' => is_string($description) ? $description : '',
                'links' => self::decodeLinks($raw['links'] ?? null),
                'imageKey' => $imageKey,
                'imageUrl' => $imageUrl,
                'videoUrl' => is_string($videoUrl) ? $videoUrl : null,
            ];
        }

        return $steps;
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    private static function decodeLinks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $links = [];
        foreach ($raw as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = $link['label'] ?? null;
            if (!is_string($label)) {
                continue;
            }
            $url = $link['url'] ?? null;
            $links[] = ['label' => $label, 'url' => is_string($url) ? $url : null];
        }

        return $links;
    }
}
