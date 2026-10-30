<?php

declare(strict_types=1);

namespace App\Notes\Infrastructure\Dbal;

use App\Notes\Application\Query\AdminNoteQueryInterface;
use App\Notes\Domain\Enum\NoteStatus;
use App\Notes\Domain\Enum\NoteTargetType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * @phpstan-import-type Author from AdminNoteQueryInterface
 * @phpstan-import-type FollowUpView from AdminNoteQueryInterface
 */
final readonly class DbalAdminNoteQuery implements AdminNoteQueryInterface
{
    private string $userTable;

    public function __construct(private Connection $connection)
    {
        $this->userTable = $connection->quoteSingleIdentifier('user');
    }

    public function forTarget(NoteTargetType $type, string $targetId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $notes = $qb
            ->select('n.id', 'n.body', 'n.status', 'n.author_id', 'u.display_name', 'n.created_at', 'n.updated_at')
            ->addSelect('(SELECT COUNT(*) FROM admin_note_revision r WHERE r.note_id = n.id) AS revision_count')
            ->from('admin_note', 'n')
            ->leftJoin('n', $this->userTable, 'u', 'u.id = n.author_id')
            ->where($qb->expr()->eq('n.target_type', ':type'))
            ->andWhere($qb->expr()->eq('n.target_id', ':target'))
            ->setParameter('type', $type->value)
            ->setParameter('target', $targetId)
            ->orderBy("CASE WHEN n.status = 'open' THEN 0 ELSE 1 END")
            ->addOrderBy('n.created_at', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
        if ([] === $notes) {
            return [];
        }

        $followUps = $this->followUps(array_map(static fn (array $row): string => self::string($row['id']), $notes));

        return array_map(fn (array $row): array => [
            'id' => self::string($row['id']),
            'body' => self::string($row['body']),
            'status' => self::string($row['status']),
            'author' => self::author($row['author_id'], $row['display_name']),
            'createdAt' => $this->date($row['created_at']),
            'updatedAt' => $this->date($row['updated_at']),
            'edited' => self::int($row['revision_count']) > 0,
            'followUps' => $followUps[self::string($row['id'])] ?? [],
        ], $notes);
    }

    public function revisions(string $noteId): ?array
    {
        $exists = $this->connection->createQueryBuilder()
            ->select('1')
            ->from('admin_note')
            ->where('id = :id')
            ->setParameter('id', $noteId)
            ->executeQuery()
            ->fetchOne();
        if (false === $exists) {
            return null;
        }

        $rows = $this->connection->createQueryBuilder()
            ->select('r.id', 'r.previous_body', 'r.editor_id', 'u.display_name', 'r.edited_at')
            ->from('admin_note_revision', 'r')
            ->leftJoin('r', $this->userTable, 'u', 'u.id = r.editor_id')
            ->where('r.note_id = :id')
            ->setParameter('id', $noteId)
            ->orderBy('r.edited_at', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(fn (array $row): array => [
            'id' => self::string($row['id']),
            'previousBody' => self::string($row['previous_body']),
            'editor' => self::author($row['editor_id'], $row['display_name']),
            'editedAt' => $this->date($row['edited_at']),
        ], $rows);
    }

    public function openCounts(NoteTargetType $type, array $targetIds): array
    {
        if ([] === $targetIds) {
            return [];
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('n.target_id', 'COUNT(*) AS open_count')
            ->from('admin_note', 'n')
            ->where($qb->expr()->eq('n.target_type', ':type'))
            ->andWhere($qb->expr()->eq('n.status', ':open'))
            ->andWhere($qb->expr()->in('n.target_id', ':ids'))
            ->setParameter('type', $type->value)
            ->setParameter('open', NoteStatus::Open->value)
            ->setParameter('ids', $targetIds, ArrayParameterType::STRING)
            ->groupBy('n.target_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [];
        foreach ($rows as $row) {
            $counts[self::string($row['target_id'])] = self::int($row['open_count']);
        }

        return $counts;
    }

    /**
     * @param list<string> $noteIds
     *
     * @return array<string, list<FollowUpView>>
     */
    private function followUps(array $noteIds): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('f.id', 'f.note_id', 'f.kind', 'f.body', 'f.author_id', 'u.display_name', 'f.created_at')
            ->from('admin_note_follow_up', 'f')
            ->leftJoin('f', $this->userTable, 'u', 'u.id = f.author_id')
            ->where($qb->expr()->in('f.note_id', ':ids'))
            ->setParameter('ids', $noteIds, ArrayParameterType::STRING)
            ->orderBy('f.created_at', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byNote = [];
        foreach ($rows as $row) {
            $byNote[self::string($row['note_id'])][] = [
                'id' => self::string($row['id']),
                'kind' => self::string($row['kind']),
                'body' => self::string($row['body']),
                'author' => self::author($row['author_id'], $row['display_name']),
                'createdAt' => $this->date($row['created_at']),
            ];
        }

        return $byNote;
    }

    /** @return Author */
    private static function author(mixed $id, mixed $displayName): array
    {
        return ['id' => self::string($id), 'displayName' => is_string($displayName) ? $displayName : null];
    }

    private function date(mixed $value): string
    {
        return new \DateTimeImmutable(self::string($value))->format(\DateTimeInterface::ATOM);
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }
}
