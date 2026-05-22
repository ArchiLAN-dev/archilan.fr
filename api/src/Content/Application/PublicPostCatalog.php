<?php

declare(strict_types=1);

namespace App\Content\Application;

use App\Content\Domain\Post;
use App\Content\Domain\PostRepositoryInterface;
use App\Shared\Infrastructure\MinioStorageInterface;

final readonly class PublicPostCatalog
{
    public function __construct(
        private PostRepositoryInterface $postRepository,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return list<array{slug: string, title: string, type: string, status: string, excerpt: string, body: list<string>, readingTime: string, publishedAt: string, relatedEventSlug: string|null, vodUrl: string|null, coverImageUrl: string|null}>
     */
    public function list(): array
    {
        $posts = $this->postRepository->findByStatus(Post::STATUS_PUBLISHED);

        return array_map(fn (Post $post): array => $this->payload($post), $posts);
    }

    /**
     * @return array{slug: string, title: string, type: string, status: string, excerpt: string, body: list<string>, readingTime: string, publishedAt: string, relatedEventSlug: string|null, vodUrl: string|null, coverImageUrl: string|null}|null
     */
    public function get(string $slug): ?array
    {
        $post = $this->postRepository->findBySlugAndStatus($slug, Post::STATUS_PUBLISHED);

        if (!$post instanceof Post) {
            return null;
        }

        return $this->payload($post);
    }

    /**
     * @return array{slug: string, title: string, type: string, status: string, excerpt: string, body: list<string>, readingTime: string, publishedAt: string, relatedEventSlug: string|null, vodUrl: string|null, coverImageUrl: string|null}
     */
    private function payload(Post $post): array
    {
        return [
            'slug' => $post->getSlug(),
            'title' => $post->getTitle(),
            'type' => $post->getType(),
            'status' => $post->getStatus(),
            'excerpt' => $post->getExcerpt(),
            'body' => $post->getBody(),
            'readingTime' => $post->getReadingTime(),
            'publishedAt' => $post->getPublishedAt()?->format(\DateTimeInterface::ATOM) ?? '',
            'relatedEventSlug' => $post->getRelatedEventSlug(),
            'vodUrl' => $post->getVodUrl(),
            'coverImageUrl' => $this->resolveCoverImageUrl($post),
        ];
    }

    private function resolveCoverImageUrl(Post $post): ?string
    {
        $key = $post->getCoverImageKey();
        if (null !== $key) {
            return $this->minioStorage->presignedUrl($this->minioMediaBucket, $key, $this->minioPresignTtl);
        }

        return $post->getCoverImageUrl();
    }
}
