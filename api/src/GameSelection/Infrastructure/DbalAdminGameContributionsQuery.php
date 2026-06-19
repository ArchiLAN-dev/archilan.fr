<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure;

use App\GameSelection\Application\AdminGameContributionsQueryInterface;
use App\GameSelection\Application\ContributionQueryFilters;
use App\GameSelection\Domain\GameTutorialContribution;
use App\GameSelection\Domain\InstallStepType;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminGameContributionsQuery implements AdminGameContributionsQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        // "user" is a reserved word in Postgres - quote it like the other DBAL queries do.
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function list(ContributionQueryFilters $filters): array
    {
        $qb = $this->connection->createQueryBuilder();
        $qb
            ->select(
                'c.id AS id',
                'c.status AS status',
                'c.created_at AS created_at',
                'c.message AS message',
                'c.steps AS steps',
                'c.proposed_game_name AS proposed_game_name',
                'g.name AS game_name',
                'g.slug AS game_slug',
                'g.install_steps AS game_install_steps',
                'u.display_name AS author_name',
            )
            ->from('game_tutorial_contribution', 'c')
            ->leftJoin('c', 'game', 'g', $qb->expr()->eq('g.id', 'c.game_id'))
            ->leftJoin('c', $this->userTable, 'u', $qb->expr()->eq('u.id', 'c.author_id'));

        if (ContributionQueryFilters::STATUS_ALL !== $filters->status) {
            $qb->andWhere($qb->expr()->eq('c.status', ':status'))
                ->setParameter('status', $filters->status);
        }

        match ($filters->target) {
            ContributionQueryFilters::TARGET_LISTED => $qb->andWhere('c.game_id IS NOT NULL'),
            ContributionQueryFilters::TARGET_UNLISTED => $qb->andWhere('c.game_id IS NULL'),
            default => $qb,
        };

        if ('' !== $filters->search) {
            $escaped = addcslashes($filters->search, '%_\\');
            $qb->andWhere('(g.name ILIKE :q OR c.proposed_game_name ILIKE :q OR u.display_name ILIKE :q OR c.message ILIKE :q)')
                ->setParameter('q', '%'.$escaped.'%');
        }

        $direction = ContributionQueryFilters::SORT_OLDEST === $filters->sort ? 'ASC' : 'DESC';
        $rows = $qb
            ->orderBy('c.created_at', $direction)
            ->addOrderBy('c.id', $direction)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(self::mapRow(...), $rows);
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
     * @return array{id: string, status: string, createdAt: string, authorName: string, message: string|null, target: string, gameSlug: string|null, proposedSteps: list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>, currentSteps: list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>}
     */
    private static function mapRow(array $row): array
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
            'proposedSteps' => self::decodeSteps($row['steps'] ?? null),
            'currentSteps' => self::decodeSteps($row['game_install_steps'] ?? null),
        ];
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>
     */
    private static function decodeSteps(mixed $raw): array
    {
        if (!is_string($raw) || '' === $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $steps = [];
        foreach ($decoded as $step) {
            if (!is_array($step)) {
                continue;
            }
            $type = $step['type'] ?? null;
            $title = $step['title'] ?? null;
            if (!is_string($type) || !is_string($title) || !InstallStepType::isValid($type)) {
                continue;
            }
            $description = $step['description'] ?? null;
            $steps[] = [
                'type' => $type,
                'title' => $title,
                'description' => is_string($description) ? $description : '',
                'links' => self::decodeLinks($step['links'] ?? null),
            ];
        }

        return $steps;
    }

    /**
     * @return list<array{label: string, url: string|null}>
     */
    private static function decodeLinks(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $links = [];
        foreach ($raw as $link) {
            if (!is_array($link)) {
                continue;
            }
            $label = $link['label'] ?? null;
            if (!is_string($label)) {
                continue;
            }
            $url = $link['url'] ?? null;
            $links[] = ['label' => $label, 'url' => is_string($url) ? $url : null];
        }

        return $links;
    }
}
