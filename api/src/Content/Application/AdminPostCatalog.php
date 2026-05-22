<?php

declare(strict_types=1);

namespace App\Content\Application;

use App\Content\Domain\Post;
use App\Content\Domain\PostRepositoryInterface;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final readonly class AdminPostCatalog
{
    private const VALID_TYPES = [Post::TYPE_NEWS, Post::TYPE_RECAP, Post::TYPE_ANNOUNCEMENT];

    public function __construct(
        private PostRepositoryInterface $postRepository,
        private MinioStorageInterface $minioStorage,
        private string $minioMediaBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $posts = $this->postRepository->findAllSortedByUpdatedAt();

        return array_map(fn (Post $post): array => $this->payload($post), $posts);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $post = $this->postRepository->findById($id);

        if (!$post instanceof Post) {
            return null;
        }

        return $this->payload($post);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{id?: string, errors: array<string, list<string>>}
     */
    public function create(array $input, \DateTimeImmutable $now): array
    {
        $errors = $this->validateInput($input, requireSlug: true);

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $post = Post::draft(
            slug: is_string($input['slug'] ?? null) ? $input['slug'] : '',
            title: is_string($input['title'] ?? null) ? $input['title'] : '',
            type: is_string($input['type'] ?? null) ? $input['type'] : '',
            excerpt: is_string($input['excerpt'] ?? null) ? $input['excerpt'] : '',
            body: $this->parseBody($input['body'] ?? null),
            readingTime: is_string($input['readingTime'] ?? null) ? $input['readingTime'] : '',
            relatedEventSlug: $this->nullableString($input['relatedEventSlug'] ?? null),
            vodUrl: $this->nullableString($input['vodUrl'] ?? null),
            now: $now,
            coverImageUrl: $this->nullableString($input['coverImageUrl'] ?? null),
        );

        try {
            $this->postRepository->save($post);
        } catch (UniqueConstraintViolationException) {
            return ['errors' => ['slug' => ['Ce slug est déjà utilisé par un autre article.']]];
        }

        return ['id' => $post->getId(), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, errors: array<string, list<string>>}
     */
    public function update(string $id, array $input, \DateTimeImmutable $now): array
    {
        $post = $this->postRepository->findById($id);

        if (!$post instanceof Post) {
            return ['found' => false, 'errors' => []];
        }

        $errors = $this->validateInput($input, requireSlug: false);

        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        $post->update(
            title: is_string($input['title'] ?? null) ? $input['title'] : $post->getTitle(),
            type: is_string($input['type'] ?? null) ? $input['type'] : $post->getType(),
            excerpt: is_string($input['excerpt'] ?? null) ? $input['excerpt'] : $post->getExcerpt(),
            body: $this->parseBody($input['body'] ?? null) ?: $post->getBody(),
            readingTime: is_string($input['readingTime'] ?? null) ? $input['readingTime'] : $post->getReadingTime(),
            relatedEventSlug: array_key_exists('relatedEventSlug', $input)
                ? $this->nullableString($input['relatedEventSlug'])
                : $post->getRelatedEventSlug(),
            vodUrl: array_key_exists('vodUrl', $input)
                ? $this->nullableString($input['vodUrl'])
                : $post->getVodUrl(),
            coverImageUrl: array_key_exists('coverImageUrl', $input)
                ? $this->nullableString($input['coverImageUrl'])
                : $post->getCoverImageUrl(),
            now: $now,
        );
        if ('url' === ($input['coverImageMode'] ?? null)) {
            $post->clearCoverImageKey($now);
        }

        $this->postRepository->save($post);

        return ['found' => true, 'errors' => []];
    }

    /**
     * @return array{found: bool}
     */
    public function publish(string $id, \DateTimeImmutable $now): array
    {
        $post = $this->postRepository->findById($id);

        if (!$post instanceof Post) {
            return ['found' => false];
        }

        $post->publish($now);
        $this->postRepository->save($post);

        return ['found' => true];
    }

    /**
     * @return array{found: bool}
     */
    public function unpublish(string $id, \DateTimeImmutable $now): array
    {
        $post = $this->postRepository->findById($id);

        if (!$post instanceof Post) {
            return ['found' => false];
        }

        $post->unpublish($now);
        $this->postRepository->save($post);

        return ['found' => true];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Post $post): array
    {
        return [
            'id' => $post->getId(),
            'slug' => $post->getSlug(),
            'title' => $post->getTitle(),
            'type' => $post->getType(),
            'status' => $post->getStatus(),
            'excerpt' => $post->getExcerpt(),
            'body' => $post->getBody(),
            'readingTime' => $post->getReadingTime(),
            'relatedEventSlug' => $post->getRelatedEventSlug(),
            'vodUrl' => $post->getVodUrl(),
            'coverImageUrl' => $this->resolveCoverImageUrl($post),
            'coverImageKey' => $post->getCoverImageKey(),
            'publishedAt' => $post->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $post->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $post->getUpdatedAt()->format(\DateTimeInterface::ATOM),
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

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, list<string>>
     */
    private function validateInput(array $input, bool $requireSlug): array
    {
        $errors = $this->buildEmptyErrors();

        if ($requireSlug) {
            $slug = is_string($input['slug'] ?? null) ? trim($input['slug']) : '';
            if ('' === $slug) {
                $errors['slug'][] = 'Le slug est requis.';
            } elseif (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors['slug'][] = 'Le slug ne doit contenir que des lettres minuscules, chiffres et tirets.';
            }
        }

        if (array_key_exists('title', $input) || $requireSlug) {
            $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
            if ('' === $title) {
                $errors['title'][] = 'Le titre est requis.';
            }
        }

        if (array_key_exists('type', $input) || $requireSlug) {
            $type = is_string($input['type'] ?? null) ? $input['type'] : '';
            if (!in_array($type, self::VALID_TYPES, true)) {
                $errors['type'][] = sprintf(
                    'Le type doit être l\'un des suivants : %s.',
                    implode(', ', self::VALID_TYPES),
                );
            }
        }

        if (array_key_exists('excerpt', $input) || $requireSlug) {
            $excerpt = is_string($input['excerpt'] ?? null) ? trim($input['excerpt']) : '';
            if ('' === $excerpt) {
                $errors['excerpt'][] = 'L\'extrait est requis.';
            }
        }

        if (array_key_exists('readingTime', $input) || $requireSlug) {
            $readingTime = is_string($input['readingTime'] ?? null) ? trim($input['readingTime']) : '';
            if ('' === $readingTime) {
                $errors['readingTime'][] = 'Le temps de lecture est requis.';
            }
        }

        return array_filter($errors, static fn (array $messages): bool => [] !== $messages);
    }

    /**
     * @return array{slug: list<string>, title: list<string>, type: list<string>, excerpt: list<string>, readingTime: list<string>}
     */
    private function buildEmptyErrors(): array
    {
        return [
            'slug' => [],
            'title' => [],
            'type' => [],
            'excerpt' => [],
            'readingTime' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function parseBody(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }
}
