<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class AdminUpdateWeeklyTemplate
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
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
        $template = $this->entityManager->find(WeeklyTemplate::class, $templateId);
        if (!$template instanceof WeeklyTemplate) {
            return null;
        }

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $template->applyChanges($changes, $now);
        $this->entityManager->flush();

        $gameRow = $this->connection->createQueryBuilder()
            ->select('g.name')
            ->from('game', 'g')
            ->where('g.id = :gameId')
            ->setParameter('gameId', $template->getGameId())
            ->executeQuery()
            ->fetchAssociative();

        $gameName = (is_array($gameRow) && is_string($gameRow['name'])) ? $gameRow['name'] : '';

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
