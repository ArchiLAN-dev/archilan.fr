<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\FriendSuggestionDismissal;
use App\Community\Domain\Repository\FriendSuggestionDismissalRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineFriendSuggestionDismissalRepository implements FriendSuggestionDismissalRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function exists(string $userId, string $dismissedUserId): bool
    {
        return null !== $this->entityManager->getRepository(FriendSuggestionDismissal::class)
            ->findOneBy(['userId' => $userId, 'dismissedUserId' => $dismissedUserId]);
    }

    public function save(FriendSuggestionDismissal $dismissal): void
    {
        $this->entityManager->persist($dismissal);
        $this->entityManager->flush();
    }
}
