<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\CounterGameCatalogueQueryInterface;
use App\Community\Application\Query\FeedCounterQueryInterface;
use App\Community\Domain\Entity\AchievementCounter;
use App\Shared\Infrastructure\Dbal\DbalFeedCounterSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Story 30.57: the counters' values for one member, by the rules of {@see DbalFeedCounterSource}, which the weekly
 * quests on a counter share (story 41.72).
 */
final readonly class DbalFeedCounterQuery implements FeedCounterQueryInterface
{
    public function __construct(
        private Connection $connection,
        private CounterGameCatalogueQueryInterface $games,
    ) {
    }

    public function values(string $userId, array $counters): array
    {
        if ([] === $counters) {
            return [];
        }

        $gameNames = $this->games->archipelagoNames(array_values(array_unique(array_map(
            static fn (AchievementCounter $counter): string => $counter->getGameId(),
            $counters,
        ))));

        $values = [];
        foreach ($counters as $counter) {
            $gameName = $gameNames[$counter->getGameId()] ?? null;
            if (null === $gameName) {
                $values[$counter->getId()] = 0;
                continue;
            }

            $query = DbalFeedCounterSource::perMember(
                $userId,
                $gameName,
                $counter->getKind()->value,
                $counter->getTargets(),
                $counter->getMode()->value,
                $counter->isWithOthersOnly(),
            );
            $qb = $this->connection->createQueryBuilder()->select('c.n')->from('('.$query['sql'].')', 'c');
            foreach ($query['params'] as $name => $value) {
                $qb->setParameter($name, $value, $query['types'][$name] ?? ParameterType::STRING);
            }
            $n = filter_var($qb->executeQuery()->fetchOne(), \FILTER_VALIDATE_INT);
            $values[$counter->getId()] = false === $n ? 0 : $n;
        }

        return $values;
    }
}
