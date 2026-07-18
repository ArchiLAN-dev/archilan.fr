<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyTemplateRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminUpdateWeeklyTemplate
{
    public function __construct(
        private WeeklyTemplateRepositoryInterface $templates,
        private GameRepositoryInterface $games,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array{name?: string|null, yamlConfig?: string, maxAttempts?: int|null, isActive?: bool} $changes
     */
    public function execute(string $templateId, array $changes): ?WeeklyTemplateResult
    {
        $template = $this->templates->findById($templateId);
        if (null === $template) {
            return null;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $template->applyChanges($changes, $now);
        $this->templates->flush();

        $game = $this->games->findById($template->getGameId());
        $gameName = $game instanceof Game ? $game->getName() : '';

        return new WeeklyTemplateResult(
            $template->getId(),
            $template->getName(),
            $template->getGameId(),
            $gameName,
            $template->getYamlConfig(),
            $template->getMaxAttempts(),
            $template->isActive(),
        );
    }
}
