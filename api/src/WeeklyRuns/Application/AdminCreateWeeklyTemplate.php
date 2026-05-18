<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminCreateWeeklyTemplate
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $gameId, string $yamlConfig, ?string $name, ?int $maxAttempts): array
    {
        $gameRow = $this->connection->createQueryBuilder()
            ->select('g.apworld_storage_key', 'g.name AS game_name')
            ->from('game', 'g')
            ->where('g.id = :gameId')
            ->setParameter('gameId', $gameId)
            ->executeQuery()
            ->fetchAssociative();

        if (false === $gameRow) {
            throw new \DomainException('game_not_ready');
        }

        if (!is_string($gameRow['apworld_storage_key']) || '' === $gameRow['apworld_storage_key']) {
            throw new \DomainException('game_not_ready');
        }

        $gameName = is_string($gameRow['game_name']) ? $gameRow['game_name'] : '';
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

        $this->entityManager->persist($template);
        $this->entityManager->flush();

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
