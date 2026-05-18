<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminDeactivateWeeklyTemplate
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function execute(string $templateId): bool
    {
        $template = $this->entityManager->find(WeeklyTemplate::class, $templateId);
        if (!$template instanceof WeeklyTemplate) {
            return false;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $template->deactivate($now);
        $this->entityManager->flush();

        return true;
    }
}
