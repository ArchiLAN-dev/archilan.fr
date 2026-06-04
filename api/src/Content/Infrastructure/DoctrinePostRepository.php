<?php

declare(strict_types=1);

namespace App\Content\Infrastructure;

use App\Content\Domain\Post;
use App\Content\Domain\PostRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrinePostRepository implements PostRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?Post
    {
        return $this->entityManager->find(Post::class, $id);
    }

    public function findBySlugAndStatus(string $slug, string $status): ?Post
    {
        /* @var Post|null */
        return $this->entityManager->getRepository(Post::class)->findOneBy([
            'slug' => $slug,
            'status' => $status,
        ]);
    }

    public function findAllSortedByUpdatedAt(int $limit = 500): array
    {
        /* @var list<Post> */
        return $this->entityManager->getRepository(Post::class)->findBy([], ['updatedAt' => 'DESC'], $limit);
    }

    public function findByStatus(string $status, int $limit = 200): array
    {
        /* @var list<Post> */
        return $this->entityManager->getRepository(Post::class)->findBy(['status' => $status], ['publishedAt' => 'DESC'], $limit);
    }

    public function save(Post $post): void
    {
        $this->entityManager->persist($post);
        $this->entityManager->flush();
    }
}
