<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Infrastructure;

use App\WeeklyRuns\Domain\WeeklyTemplate;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;

final readonly class DoctrineWeeklyTemplateRepository implements WeeklyTemplateRepositoryInterface
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function findById(string $id): ?WeeklyTemplate
    {
        return $this->entityManager->find(WeeklyTemplate::class, $id);
    }

    public function findAllActive(): array
    {
        /* @var list<WeeklyTemplate> */
        return $this->entityManager->getRepository(WeeklyTemplate::class)->findBy([
            'isActive' => true,
        ]);
    }

    public function save(WeeklyTemplate $template): void
    {
        $this->entityManager->persist($template);
        $this->entityManager->flush();
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
