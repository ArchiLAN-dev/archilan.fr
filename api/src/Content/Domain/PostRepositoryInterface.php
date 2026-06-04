<?php

declare(strict_types=1);

namespace App\Content\Domain;

interface PostRepositoryInterface
{
    public function findById(string $id): ?Post;

    public function findBySlugAndStatus(string $slug, string $status): ?Post;

    /**
     * @return list<Post>
     */
    public function findAllSortedByUpdatedAt(int $limit = 500): array;

    /**
     * @return list<Post>
     */
    public function findByStatus(string $status, int $limit = 200): array;

    public function save(Post $post): void;
}
