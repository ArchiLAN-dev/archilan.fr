<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Doctrine;

use App\Community\Domain\Entity\DiscordBanNotice;
use App\Community\Domain\Repository\DiscordBanNoticeRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineDiscordBanNoticeRepository implements DiscordBanNoticeRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function all(): array
    {
        /** @var list<DiscordBanNotice> $notices */
        $notices = $this->entityManager->getRepository(DiscordBanNotice::class)->findAll();

        return $notices;
    }

    public function save(DiscordBanNotice $notice): void
    {
        $this->entityManager->persist($notice);
    }

    public function remove(DiscordBanNotice $notice): void
    {
        $this->entityManager->remove($notice);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
