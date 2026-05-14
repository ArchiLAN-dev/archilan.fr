<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRequest;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class GameRequests
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $connection,
    ) {
    }

    /**
     * @return list<array{normalizedName: string, displayName: string, voteCount: int, hasVoted: bool}>
     */
    public function list(?string $userId): array
    {
        $hasVotedCol = null !== $userId
            ? ', BOOL_OR(user_id = :userId) AS has_voted'
            : ', false AS has_voted';

        $sql = <<<SQL
            SELECT
                normalized_name,
                MIN(game_name) AS display_name,
                COUNT(*) AS vote_count
                {$hasVotedCol}
            FROM game_requests
            GROUP BY normalized_name
            ORDER BY vote_count DESC, normalized_name ASC
            LIMIT 50
        SQL;

        $params = null !== $userId ? ['userId' => $userId] : [];
        $rows = $this->connection->fetchAllAssociative($sql, $params);

        return array_map(fn (array $row) => [
            'normalizedName' => is_string($row['normalized_name']) ? $row['normalized_name'] : '',
            'displayName' => is_string($row['display_name']) ? $row['display_name'] : '',
            'voteCount' => is_numeric($row['vote_count']) ? (int) $row['vote_count'] : 0,
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
