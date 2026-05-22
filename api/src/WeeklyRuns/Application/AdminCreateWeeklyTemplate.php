<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminCreateWeeklyTemplate
{
    public function __construct(
        private GameRepositoryInterface $games,
        private WeeklyTemplateRepositoryInterface $templates,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $gameId, string $yamlConfig, ?string $name, ?int $maxAttempts): array
    {
        $game = $this->games->findById($gameId);
        if (!$game instanceof Game) {
            throw new \DomainException('game_not_ready');
        }

        if (!$game->isApworldReady()) {
            throw new \DomainException('game_not_ready');
        }

        $gameName = $game->getName();
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));

        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $gameId,
            yamlConfig: $yamlConfig,
            name: $name,
            maxAttempts: $maxAttempts,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );

        $this->templates->save($template);

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
