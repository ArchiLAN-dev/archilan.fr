<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\WeeklyRuns\Application\Handler\GenerateWeeklyRunsMessageHandler;
use App\WeeklyRuns\Domain\Repository\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\Repository\WeeklyTemplateRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminWeeklyTemplateDetailQuery
{
    public function __construct(
        private WeeklyTemplateRepositoryInterface $templates,
        private GameRepositoryInterface $games,
        private WeeklyRunRepositoryInterface $runs,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $templateId): ?array
    {
        $template = $this->templates->findById($templateId);
        if (null === $template) {
            return null;
        }

        $game = $this->games->findById($template->getGameId());
        $gameName = $game instanceof Game ? $game->getName() : '';

        // Same ISO-week computation as GenerateWeeklyRunsMessageHandler - drives the
        // on-demand "generate the week's run" button's disabled state on the template page.
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $weekYear = (int) $now->format('o');
        $weekNumber = (int) $now->format('W');

        return [
            'id' => $template->getId(),
            'name' => $template->getName(),
            'gameId' => $template->getGameId(),
            'gameName' => $gameName,
            'yamlConfig' => $template->getYamlConfig(),
            'maxAttempts' => $template->getMaxAttempts(),
            'isActive' => $template->isActive(),
            'currentWeekHasRun' => $this->runs->existsByTemplateAndWeek($templateId, $weekYear, $weekNumber),
        ];
    }
}
