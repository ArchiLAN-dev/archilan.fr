<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

use App\Content\Application\Query\AdminPostView;
use App\Content\Application\Service\AdminPostCatalog;
use App\Content\Domain\Entity\Post;
use App\Content\Domain\Repository\PostRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;

final readonly class UploadPostCoverImageCommand
{
    public function __construct(
        private PostRepositoryInterface $postRepository,
        private MinioStorageInterface $minioStorage,
        private AdminPostCatalog $adminPostCatalog,
        private ClockInterface $clock,
        private PublicMediaUrlResolver $publicMedia,
    ) {
    }

    /**
     * @throws NotFoundException           when the post does not exist
     * @throws ServiceUnavailableException when the object storage upload fails
     */
    public function execute(string $postId, string $key, string $contents): ?AdminPostView
    {
        $post = $this->postRepository->findById($postId);

        if (!$post instanceof Post) {
            throw new NotFoundException('Article introuvable.');
        }

        try {
            $this->minioStorage->upload($this->publicMedia->bucket(), $key, $contents);
        } catch (\Throwable) {
            throw new ServiceUnavailableException('Le stockage est indisponible.', 'storage_unavailable');
        }

        $post->attachCoverImage($key, $this->clock->now());
        $this->postRepository->save($post);

        return $this->adminPostCatalog->get($postId);
    }
}
