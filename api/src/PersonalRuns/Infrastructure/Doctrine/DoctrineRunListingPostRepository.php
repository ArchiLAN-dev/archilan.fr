<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Doctrine;

use App\PersonalRuns\Domain\Entity\RunListingPost;
use App\PersonalRuns\Domain\Repository\RunListingPostRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineRunListingPostRepository implements RunListingPostRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function all(): array
    {
        $posts = [];
        foreach ($this->entityManager->getRepository(RunListingPost::class)->findAll() as $post) {
            $posts[$post->getRunId()] = $post;
        }

        return $posts;
    }

    public function save(RunListingPost $post): void
    {
        $this->entityManager->persist($post);
        $this->entityManager->flush();
    }
}
