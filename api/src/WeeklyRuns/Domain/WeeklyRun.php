<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'weekly_runs')]
final class WeeklyRun
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_FINISHED = 'finished';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\Column(name: 'template_id', type: Types::STRING, length: 36)]
        private string $templateId,
        #[ORM\Column(name: 'week_year', type: Types::INTEGER)]
        private int $weekYear,
        #[ORM\Column(name: 'week_number', type: Types::INTEGER)]
        private int $weekNumber,
        #[ORM\Column(type: Types::STRING, length: 100)]
        private string $seed,
        #[ORM\Column(type: Types::STRING, length: 10)]
        private string $status,
        #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startedAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $finishedAt = null,
    ) {
    }

    public function finish(\DateTimeImmutable $finishedAt): void
    {
        if (self::STATUS_FINISHED === $this->status) {
            return;
        }

        $this->status = self::STATUS_FINISHED;
        $this->finishedAt = $finishedAt;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTemplateId(): string
    {
        return $this->templateId;
    }

    public function getWeekYear(): int
    {
        return $this->weekYear;
    }

    public function getWeekNumber(): int
    {
        return $this->weekNumber;
    }

    public function getSeed(): string
    {
        return $this->seed;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
