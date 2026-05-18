<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\Shared\Infrastructure\MinioStorageInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Yaml\Yaml;

final readonly class LaunchWeeklyEntry
{
    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
        private WeeklyRunnerGatewayInterface $gateway,
        private MinioStorageInterface $minioStorage,
        private ClockInterface $clock,
        private string $minioApworldsBucket,
        private int $minioPresignTtl,
    ) {
    }

    /**
     * @return array{entryId: string, externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}}
     */
    public function execute(string $weeklyRunId, string $entryId, string $userId): array
    {
        $run = $this->entityManager->find(WeeklyRun::class, $weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            throw new \DomainException('run_not_found');
        }

        if (WeeklyRun::STATUS_ACTIVE !== $run->getStatus()) {
            throw new \DomainException('run_not_active');
        }

        $entry = $this->entityManager->find(WeeklyEntry::class, $entryId);
        if (!$entry instanceof WeeklyEntry) {
            throw new \DomainException('entry_not_found');
        }

        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            throw new \DomainException('forbidden');
        }

        if (null !== $entry->getExternalSessionId()) {
            throw new \DomainException('session_already_started');
        }

        $template = $this->entityManager->find(WeeklyTemplate::class, $run->getTemplateId());
        if (!$template instanceof WeeklyTemplate) {
            throw new \DomainException('run_not_found');
        }

        $gameRow = $this->connection->createQueryBuilder()
            ->select('g.apworld_storage_key')
            ->from('game', 'g')
            ->where('g.id = :gameId')
            ->setParameter('gameId', $template->getGameId())
            ->executeQuery()
            ->fetchAssociative();

        $storageKeyRaw = false !== $gameRow ? $gameRow['apworld_storage_key'] : null;
        if (!is_string($storageKeyRaw) || '' === $storageKeyRaw) {
            throw new \DomainException('game_not_ready');
        }

        $apworldStorageKey = $storageKeyRaw;

        $apworldDownloadUrl = $this->minioStorage->presignedUrl(
            $this->minioApworldsBucket,
            $apworldStorageKey,
            $this->minioPresignTtl,
        );

        $userTable = $this->connection->quoteSingleIdentifier('user');
        $userRow = $this->connection->createQueryBuilder()
            ->select('u.display_name')
            ->from($userTable, 'u')
            ->where('u.id = :userId')
            ->setParameter('userId', $userId)
            ->executeQuery()
            ->fetchAssociative();

        $displayName = (is_array($userRow) && is_string($userRow['display_name'])) ? $userRow['display_name'] : 'ArchiLAN';

        $parsed = Yaml::parse($template->getYamlConfig());
        if (!is_array($parsed)) {
            $parsed = [];
        }
        $parsed['name'] = $displayName;
        $substitutedYaml = Yaml::dump($parsed, 4, 2);

        $result = $this->gateway->launchEntry(
            $entryId,
            $run->getSeed(),
            $apworldStorageKey,
            $apworldDownloadUrl,
            $displayName,
            $substitutedYaml,
        );

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entry->launch($result['externalSessionId'], $now, $result['connectionInfo']);

        try {
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            try {
                $this->gateway->terminate($result['externalSessionId']);
            } catch (\Throwable) {
            }

            throw $e;
        }

        return [
            'entryId' => $entryId,
            'externalSessionId' => $result['externalSessionId'],
            'connectionInfo' => $result['connectionInfo'],
        ];
    }
}
