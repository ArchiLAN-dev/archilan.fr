<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\MyGameTutorialContributionsQueryInterface;
use Doctrine\DBAL\Connection;

final readonly class DbalMyGameTutorialContributionsQuery implements MyGameTutorialContributionsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forAuthor(string $authorId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'c.id AS id',
                'c.status AS status',
                'c.proposed_game_name AS proposed_game_name',
                'c.steps AS steps',
                'c.created_at AS created_at',
                'g.name AS game_name',
            )
            ->from('game_tutorial_contribution', 'c')
            ->leftJoin('c', 'game', 'g', $qb->expr()->eq('g.id', 'c.game_id'))
            ->where($qb->expr()->eq('c.author_id', ':authorId'))
            ->setParameter('authorId', $authorId)
            ->orderBy('c.created_at', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::mapRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, status: string, target: string, stepCount: int, createdAt: string}
     */
    private static function mapRow(array $row): array
    {
        $id = $row['id'] ?? null;
        $status = $row['status'] ?? null;
        $gameName = $row['game_name'] ?? null;
        $proposedName = $row['proposed_game_name'] ?? null;
        $createdAt = $row['created_at'] ?? null;

        $target = is_string($gameName) && '' !== $gameName
            ? $gameName
            : (is_string($proposedName) ? $proposedName : '');

        return [
            'id' => is_string($id) ? $id : '',
            'status' => is_string($status) ? $status : '',
            'target' => $target,
            'stepCount' => self::countSteps($row['steps'] ?? null),
            'createdAt' => is_string($createdAt) ? $createdAt : '',
        ];
    }

    private static function countSteps(mixed $raw): int
    {
        if (!is_string($raw) || '' === $raw) {
            return 0;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? count($decoded) : 0;
    }
}
