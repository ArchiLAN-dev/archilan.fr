<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Dbal;

use App\GameSelection\Application\Query\AdminGameContributionsQueryInterface;
use App\GameSelection\Application\Query\ContributionQueryFilters;
use App\GameSelection\Application\Support\InstallStepsReader;
use App\GameSelection\Domain\Entity\GameTutorialContribution;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

final readonly class DbalAdminGameContributionsQuery implements AdminGameContributionsQueryInterface
{
    private string $userTable;

    public function __construct(
        private Connection $connection,
        private InstallStepsReader $installStepsReader,
    ) {
        // "user" is a reserved word in Postgres - quote it like the other DBAL queries do.
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function find(string $id): ?array
    {
        $qb = $this->baseQuery();
        $row = $qb
            ->where($qb->expr()->eq('c.id', ':id'))
            ->setParameter('id', $id)
            ->executeQuery()
            ->fetchAssociative();

        return false === $row ? null : $this->mapRow($row);
    }

    public function list(ContributionQueryFilters $filters): array
    {
        $qb = $this->baseQuery();

        if (ContributionQueryFilters::STATUS_ALL !== $filters->status) {
            $qb->andWhere($qb->expr()->eq('c.status', ':status'))
                ->setParameter('status', $filters->status);
        }

        match ($filters->target) {
            ContributionQueryFilters::TARGET_LISTED => $qb->andWhere('c.game_id IS NOT NULL'),
            ContributionQueryFilters::TARGET_UNLISTED => $qb->andWhere('c.game_id IS NULL'),
            default => $qb,
        };

        if (null !== $filters->gameId) {
            $qb->andWhere($qb->expr()->eq('c.game_id', ':gameId'))
                ->setParameter('gameId', $filters->gameId);
        }

        if ('' !== $filters->search) {
            $escaped = addcslashes($filters->search, '%_\\');
            $qb->andWhere('(g.name ILIKE :q OR c.proposed_game_name ILIKE :q OR COALESCE(cp.display_name, u.display_name) ILIKE :q OR c.message ILIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        $direction = ContributionQueryFilters::SORT_OLDEST === $filters->sort ? 'ASC' : 'DESC';
        $rows = $qb
            ->orderBy('c.created_at', $direction)
            ->addOrderBy('c.id', $direction)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->mapRow(...), $rows);
    }

    /** A contribution with its game (absent for an unlisted one) and its author's public name. */
    private function baseQuery(): QueryBuilder
    {
        $qb = $this->connection->createQueryBuilder();

        return $qb
            ->select(
                'c.id AS id',
                'c.status AS status',
                'c.created_at AS created_at',
                'c.message AS message',
                'c.steps AS steps',
                'c.proposed_game_name AS proposed_game_name',
                'c.game_id AS game_id',
                'c.reviewed_at AS reviewed_at',
                'c.rejection_reason AS rejection_reason',
                'g.name AS game_name',
                'g.slug AS game_slug',
                'g.install_steps AS game_install_steps',
                'COALESCE(cp.display_name, u.display_name) AS author_name',
            )
            ->from('game_tutorial_contribution', 'c')
            ->leftJoin('c', 'game', 'g', $qb->expr()->eq('g.id', 'c.game_id'))
            ->leftJoin('c', $this->userTable, 'u', $qb->expr()->eq('u.id', 'c.author_id'))
            ->leftJoin('u', 'community_profile', 'cp', $qb->expr()->eq('cp.user_id', 'u.id'));
    }

    public function pendingCount(): int
    {
        $qb = $this->connection->createQueryBuilder();
        $count = $qb
            ->select('COUNT(c.id)')
            ->from('game_tutorial_contribution', 'c')
            ->where($qb->expr()->eq('c.status', ':status'))
            ->setParameter('status', GameTutorialContribution::STATUS_PENDING)
            ->executeQuery()
            ->fetchOne();

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: string, status: string, createdAt: string, authorName: string, message: string|null, target: string, gameSlug: string|null, gameId: string|null, reviewedAt: string|null, rejectionReason: string|null, proposedSteps: list<array{type: string, title: string, description: string, videoUrl: string|null}>, currentSteps: list<array{type: string, title: string, description: string, videoUrl: string|null}>}
     */
    private function mapRow(array $row): array
    {
        $id = $row['id'] ?? null;
        $status = $row['status'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $authorName = $row['author_name'] ?? null;
        $message = $row['message'] ?? null;
        $gameName = $row['game_name'] ?? null;
        $gameSlug = $row['game_slug'] ?? null;
        $proposedName = $row['proposed_game_name'] ?? null;

        $target = is_string($gameName) && '' !== $gameName
            ? $gameName
            : (is_string($proposedName) ? $proposedName : '');

        return [
            'id' => is_string($id) ? $id : '',
            'status' => is_string($status) ? $status : '',
            'createdAt' => is_string($createdAt) ? $createdAt : '',
            'authorName' => is_string($authorName) ? $authorName : '',
            'message' => is_string($message) ? $message : null,
            'target' => $target,
            'gameSlug' => is_string($gameSlug) ? $gameSlug : null,
            // Story 39.16: the game's editor to link to, and the decision once taken.
            'gameId' => is_string($row['game_id'] ?? null) ? $row['game_id'] : null,
            'reviewedAt' => is_string($row['reviewed_at'] ?? null) ? $row['reviewed_at'] : null,
            'rejectionReason' => is_string($row['rejection_reason'] ?? null) ? $row['rejection_reason'] : null,
            'proposedSteps' => $this->installStepsReader->presentJson($row['steps'] ?? null),
            'currentSteps' => $this->installStepsReader->presentJson($row['game_install_steps'] ?? null),
        ];
    }
}
