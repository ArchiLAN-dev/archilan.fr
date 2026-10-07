<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Query;

use App\Shared\Infrastructure\Dbal\DbalSlotCheckSource;
use App\Shared\Infrastructure\Dbal\DbalSlotPlayerSource;
use App\Wallet\Application\Query\WelcomeQuestsQueryInterface;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Enum\WelcomeStep;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Story 41.25: who did each first step, from the account (Discord), the session feed, slot goals and weekly
 * attempts - read in plain SQL like the weekly quests, without making Wallet depend on Sessions or WeeklyRuns.
 * Every read is narrowed to the given members inside the scans, never filtered after them: the run passes every
 * 5 minutes over a session feed that only grows.
 */
final readonly class DbalWelcomeQuestsQuery implements WelcomeQuestsQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function candidates(?\DateTimeImmutable $since): array
    {
        $params = ['reason' => PelleReason::WelcomeReward->value, 'steps' => \count(WelcomeStep::cases())];
        $joined = '';
        if (null !== $since) {
            $joined = 'AND u.created_at >= :since';
            $params['since'] = $since->format(\DATE_ATOM);
        }

        return $this->ids($this->connection->fetchFirstColumn(
            'SELECT u.id FROM "user" u
              WHERE u.banned_at IS NULL AND u.deleted_at IS NULL '.$joined.'
                AND (SELECT COUNT(*) FROM pelle_movement pm WHERE pm.user_id = u.id AND pm.reason = :reason) < :steps',
            $params,
        ));
    }

    public function unpaid(WelcomeStep $step, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }
        // Story 41.25 review: a Discord account pays once, whichever site account it is linked to.
        $discord = WelcomeStep::Discord === $step
            ? 'AND NOT EXISTS (SELECT 1 FROM pelle_movement pd WHERE pd.unique_key = CONCAT(CAST(:discordPrefix AS VARCHAR), u.discord_id))'
            : '';

        $rows = $this->connection->fetchAllAssociative(
            'SELECT DISTINCT d.uid, u.discord_id FROM ('.self::done($step).') d
               JOIN "user" u ON u.id = d.uid
              WHERE NOT EXISTS (
                    SELECT 1 FROM pelle_movement pm
                     WHERE pm.user_id = d.uid AND pm.reason = :reason AND pm.unique_key LIKE :prefix
              ) '.$discord,
            DbalSlotCheckSource::params() + [
                'uids' => $userIds,
                'reason' => PelleReason::WelcomeReward->value,
                'prefix' => sprintf('welcome:%s:%%', $step->value),
                'discordPrefix' => WelcomeStep::DISCORD_KEY_PREFIX,
            ],
            DbalSlotCheckSource::types() + ['uids' => ArrayParameterType::STRING],
        );

        $unpaid = [];
        foreach ($rows as $row) {
            $uid = $row['uid'] ?? null;
            if (is_string($uid) && '' !== $uid) {
                $discordId = $row['discord_id'] ?? null;
                $unpaid[] = ['userId' => $uid, 'discordId' => is_string($discordId) ? $discordId : null];
            }
        }

        return $unpaid;
    }

    public function doneBy(string $userId): array
    {
        $done = [];
        foreach (WelcomeStep::cases() as $step) {
            $found = $this->connection->fetchOne(
                'SELECT 1 FROM ('.self::done($step).') d LIMIT 1',
                DbalSlotCheckSource::params() + ['uids' => [$userId]],
                DbalSlotCheckSource::types() + ['uids' => ArrayParameterType::STRING],
            );
            if (false !== $found) {
                $done[] = $step;
            }
        }

        return $done;
    }

    public function paidTo(string $userId): array
    {
        $keys = $this->connection->fetchFirstColumn(
            'SELECT unique_key FROM pelle_movement WHERE user_id = :userId AND reason = :reason AND unique_key IS NOT NULL',
            ['userId' => $userId, 'reason' => PelleReason::WelcomeReward->value],
        );

        $paid = [];
        foreach ($keys as $key) {
            // welcome:{step}:{member}, or welcome:discord:discord-{discord id}
            $step = WelcomeStep::tryFrom(explode(':', is_string($key) ? $key : '')[1] ?? '');
            if (null !== $step) {
                $paid[] = $step;
            }
        }

        return $paid;
    }

    public function joinedAt(string $userId): ?\DateTimeImmutable
    {
        $createdAt = $this->connection->fetchOne('SELECT created_at FROM "user" WHERE id = :userId', ['userId' => $userId]);
        if (!is_string($createdAt)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($createdAt);
        } catch (\Exception) {
            return null;
        }
    }

    /** The members (`uid`) among `:uids` who did the step; the filter sits inside each scan. */
    private static function done(WelcomeStep $step): string
    {
        $checks = DbalSlotCheckSource::from();
        $player = DbalSlotCheckSource::player();
        $weeklyCheck = 'EXISTS (SELECT 1 FROM session_feed_event wf WHERE wf.session_id = we.external_session_id AND wf.type IN (:checkTypes))';
        $players = DbalSlotPlayerSource::expression('session_slot', 'registration');

        return match ($step) {
            WelcomeStep::Discord => 'SELECT id AS uid FROM "user" WHERE id IN (:uids) AND discord_id IS NOT NULL',
            WelcomeStep::FirstCheck => "SELECT {$player} AS uid {$checks} AND {$player} IN (:uids)
                 UNION
                 SELECT we.user_id AS uid FROM weekly_entries we
                  WHERE we.user_id IN (:uids)
                    AND (we.goal_reached_at IS NOT NULL OR COALESCE(we.checks_total, 0) > 0 OR {$weeklyCheck})",
            WelcomeStep::FirstWeekly => "SELECT DISTINCT we.user_id AS uid FROM weekly_entries we
                  WHERE we.user_id IN (:uids) AND we.launched_at IS NOT NULL
                    AND (we.goal_reached_at IS NOT NULL OR COALESCE(we.checks_total, 0) > 0 OR {$weeklyCheck})",
            // The member's sessions first (narrowed), then anyone else who made a check in one of them.
            WelcomeStep::FirstPartner => "SELECT DISTINCT a.uid
                   FROM (SELECT DISTINCT f.session_id, {$player} AS uid {$checks} AND {$player} IN (:uids)) a
                  WHERE EXISTS (
                        SELECT 1 FROM session_feed_event f
                          JOIN session_slot slot ON slot.session_id = f.session_id AND slot.slot_name = f.sender_name
                          JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                         WHERE f.type IN (:checkTypes) AND f.session_id = a.session_id AND '.$player.' <> a.uid
                  )',
            WelcomeStep::FirstGoal => 'SELECT sp.'.DbalSlotPlayerSource::USER_COLUMN." AS uid
                   FROM session_slot slot
                   JOIN {$players} sp ON sp.".DbalSlotPlayerSource::SLOT_COLUMN.' = slot.id
                  WHERE slot.goal_reached_at IS NOT NULL AND sp.'.DbalSlotPlayerSource::USER_COLUMN.' IN (:uids)
                 UNION
                 SELECT user_id AS uid FROM weekly_entries WHERE goal_reached_at IS NOT NULL AND user_id IN (:uids)',
        };
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    private function ids(array $values): array
    {
        return array_values(array_filter($values, static fn (mixed $value): bool => is_string($value) && '' !== $value));
    }
}
