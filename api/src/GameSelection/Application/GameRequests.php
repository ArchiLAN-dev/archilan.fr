<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRequest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GameRequests
{
    private string $table;

    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
    ) {
        $this->table = $em->getClassMetadata(GameRequest::class)->getTableName();
    }

    /**
     * @return list<array{normalizedName: string, displayName: string, voteCount: int, hasVoted: bool}>
     */
    public function list(?string $userId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb->select(
            'gr.normalized_name',
            'MIN(gr.game_name) AS display_name',
            'COUNT(*) AS vote_count',
        )
            ->from($this->table, 'gr')
            ->groupBy('gr.normalized_name')
            ->orderBy('vote_count', 'DESC')
            ->addOrderBy('gr.normalized_name', 'ASC')
            ->setMaxResults(50);

        if (null !== $userId) {
            $qb->addSelect('BOOL_OR(gr.user_id = :userId) AS has_voted')
                ->setParameter('userId', $userId);
        } else {
            $qb->addSelect('FALSE AS has_voted');
        }

        $rows = $qb->executeQuery()->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'normalizedName' => is_string($row['normalized_name'] ?? null) ? $row['normalized_name'] : '',
            'displayName' => is_string($row['display_name'] ?? null) ? $row['display_name'] : '',
            'voteCount' => is_numeric($row['vote_count'] ?? null) ? (int) $row['vote_count'] : 0,
            'hasVoted' => (bool) ($row['has_voted'] ?? false),
        ], $rows);
    }

    public function submit(string $gameName, string $userId, \DateTimeImmutable $now): void
    {
        $request = GameRequest::create($gameName, $userId, $now);
        $this->em->persist($request);
        $this->em->flush();
    }

    public function cancel(string $normalizedName, string $userId): void
    {
        /** @var GameRequest|null $request */
        $request = $this->em->getRepository(GameRequest::class)->findOneBy([
            'normalizedName' => $normalizedName,
            'userId' => $userId,
        ]);

        if (null === $request) {
            return;
        }

        $this->em->remove($request);
        $this->em->flush();
    }
}
