<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

/**
 * Admin-facing read view of a {@see \App\Content\Domain\Entity\Post}. Produced by {@see AdminPostCatalog}
 * (list/get) and returned by {@see \App\Content\Application\Command\UploadPostCoverImageCommand} after it
 * re-derives the cover URL; the admin controllers serialize it verbatim as the `data` payload.
 */
final readonly class AdminPostView
{
    /**
     * @param list<string> $body
     */
    public function __construct(
        public string $id,
        public string $slug,
        public string $title,
        public string $type,
        public string $status,
        public string $excerpt,
        public array $body,
        public string $readingTime,
        public ?string $coverImageUrl,
        public ?string $coverImageKey,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
