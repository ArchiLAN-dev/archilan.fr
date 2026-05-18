<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminWeeklyTemplateDetailQuery
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function execute(string $templateId): ?array
    {
        $template = $this->entityManager->find(WeeklyTemplate::class, $templateId);
        if (!$template instanceof WeeklyTemplate) {
            return null;
        }

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
