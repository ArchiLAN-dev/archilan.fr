<?php

declare(strict_types=1);

namespace App\CatalogSync\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class CheckApworldUpdatesService
{
    public function __construct(
        private ApworldVersionChecker $checker,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{checked: int, rateLimitHit: bool}
     */
    public function checkAll(): array
    {
        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g', 'cs')
            ->from(ArchipelagoGame::class, 'g')
            ->join('g.catalogSync', 'cs')
            ->where('cs.apworldSourceUrl IS NOT NULL')
            ->andWhere('cs.apworldSourceUrl LIKE :prefix')
            ->setParameter('prefix', 'https://github.com/%')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();

        $checked = 0;
        $rateLimitHit = false;

        foreach ($games as $game) {
            try {
                $info = $this->checker->check($game);

                if (null !== $info) {
                    ++$checked;
                    $this->logger->info('catalog_sync.apworld_checked', [
                        'game' => $game->getName(),
                        'latestTag' => $info->latestTag,
                        'updateStatus' => $info->updateStatus,
                    ]);
                }
            } catch (GithubRateLimitException $e) {
                $rateLimitHit = true;
                $this->logger->warning('catalog_sync.rate_limit_hit', ['message' => $e->getMessage()]);
                break;
            }
        }

        $this->entityManager->flush();

        return ['checked' => $checked, 'rateLimitHit' => $rateLimitHit];
    }
}
