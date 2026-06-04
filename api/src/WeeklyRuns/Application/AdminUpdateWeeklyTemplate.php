<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
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
     *
     * @return array<string, mixed>|null null when not found
     */
    public function execute(string $templateId, array $changes): ?array
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

        return [
            'id' => $template->getId(),
            'name' => $template->getName(),
            'gameId' => $template->getGameId(),
            'gameName' => $gameName,
            'yamlConfig' => $template->getYamlConfig(),
            'maxAttempts' => $template->getMaxAttempts(),
            'isActive' => $template->isActive(),
        ];
    }
}
