<?php

declare(strict_types=1);

namespace App\Content\Application;

use App\Content\Domain\Post;
use App\Shared\Application\EntityFinderTrait;
use App\Shared\Infrastructure\MinioStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class UploadPostCoverImageCommand
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
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
        try {
            $post = $this->findOrFail(Post::class, $postId);
        } catch (\RuntimeException) {
            return ['outcome' => 'not_found', 'data' => null];
        }

        try {
            $this->minioStorage->upload($this->minioMediaBucket, $key, $contents);
        } catch (\Throwable) {
            return ['outcome' => 'storage_error', 'data' => null];
        }

        $post->setCoverImageKey($key, new \DateTimeImmutable());
        $this->entityManager->flush();

        return ['outcome' => 'ok', 'data' => $this->adminPostCatalog->get($postId)];
    }
}
