<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;

final readonly class AdminWeeklyTemplateDetailQuery
{
    public function __construct(
        private WeeklyTemplateRepositoryInterface $templates,
        private GameRepositoryInterface $games,
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
