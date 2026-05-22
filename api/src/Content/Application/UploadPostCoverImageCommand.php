<?php

declare(strict_types=1);

namespace App\Content\Application;

use App\Content\Domain\Post;
use App\Content\Domain\PostRepositoryInterface;
use App\Shared\Infrastructure\MinioStorageInterface;

final readonly class UploadPostCoverImageCommand
{
    public function __construct(
        private PostRepositoryInterface $postRepository,
        private MinioStorageInterface $minioStorage,
        private AdminPostCatalog $adminPostCatalog,
        private string $minioMediaBucket,
    ) {
    }

    /**
     * @return array{outcome: 'not_found'|'storage_error'|'ok', data: array<string, mixed>|null}
     */
    public function execute(string $postId, string $key, string $contents): array
    {
        $post = $this->postRepository->findById($postId);

        if (!$post instanceof Post) {
            return ['outcome' => 'not_found', 'data' => null];
        }

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return ['outcome' => 'storage_error', 'data' => null];
        }

        $post->setCoverImageKey($key, new \DateTimeImmutable());
        $this->postRepository->save($post);

        return ['outcome' => 'ok', 'data' => $this->adminPostCatalog->get($postId)];
    }
}
