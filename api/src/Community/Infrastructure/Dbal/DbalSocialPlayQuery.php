<?php

declare(strict_types=1);

namespace App\Community\Infrastructure\Dbal;

use App\Community\Application\Query\SocialPlayQueryInterface;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use Doctrine\DBAL\Connection;

/**
 * One aggregated read over the sessions' players, as {@see DbalFriendSuggestionsQuery} does (story 43.2), then the
 * weekly duels won.
 */
final readonly class DbalSocialPlayQuery implements SocialPlayQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forUser(string $userId): array
    {
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');
        $slotColumn = DbalSlotPlayerSource::SLOT_COLUMN;
        $userColumn = DbalSlotPlayerSource::USER_COLUMN;

        $row = $this->connection->fetchAssociative(
            "WITH players AS (
                 SELECT DISTINCT slot.session_id, sp.{$userColumn} AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.{$slotColumn} = slot.id
             ), shared AS (
                 SELECT p.uid, p.session_id, s.finished_at
                   FROM players p
                   JOIN players me ON me.session_id = p.session_id AND me.uid = :userId
                   JOIN session s ON s.id = p.session_id
                  WHERE p.uid <> :userId
                    AND (s.event_id IS NOT NULL OR EXISTS (SELECT 1 FROM run r WHERE r.session_id = s.id))
             ), per_person AS (
                 SELECT uid, COUNT(finished_at) AS finished FROM shared GROUP BY uid
             )
             SELECT (SELECT COUNT(*) FROM per_person) AS coplayers,
                    (SELECT COUNT(*) FROM per_person pp
                      WHERE EXISTS (SELECT 1 FROM community_friendship f
                                     WHERE f.status = 'accepted'
                                       AND ((f.requester_id = :userId AND f.addressee_id = pp.uid)
                                         OR (f.requester_id = pp.uid AND f.addressee_id = :userId)))) AS friends,
                    (SELECT COALESCE(MAX(finished), 0) FROM per_person) AS max_finished,
                    (SELECT COUNT(*) FROM weekly_duel d WHERE d.winner_id = :userId) AS duels_won",
            ['userId' => $userId],
        );

        return [
            'coplayers' => self::int($row['coplayers'] ?? null),
            'friends' => self::int($row['friends'] ?? null),
            'maxFinishedWithSamePerson' => self::int($row['max_finished'] ?? null),
            'weeklyDuelsWon' => self::int($row['duels_won'] ?? null),
        ];
    }

    private static function int(mixed $value): int
    {
        $int = filter_var($value, \FILTER_VALIDATE_INT);

        return false === $int ? 0 : $int;
    }
}
