<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminDeactivateWeeklyTemplate
{
    public function __construct(
        private WeeklyTemplateRepositoryInterface $templates,
        private ClockInterface $clock,
    ) {
    }

    public function execute(string $templateId): bool
    {
        $template = $this->templates->findById($templateId);
        if (null === $template) {
            return false;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $template->deactivate($now);
        $this->templates->flush();

        return true;
    }
}
