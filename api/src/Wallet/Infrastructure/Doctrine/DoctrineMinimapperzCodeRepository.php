<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Doctrine;

use App\Wallet\Domain\Repository\MinimapperzCodeRepositoryInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Story 49.2: the codes are handed out in SQL, one statement per member. `FOR UPDATE SKIP LOCKED` keeps two members
 * off the same free code; the unique (event, member) index keeps one member off two codes.
 */
final readonly class DoctrineMinimapperzCodeRepository implements MinimapperzCodeRepositoryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function addCodes(string $eventId, array $codes, \DateTimeImmutable $now): int
    {
        $added = 0;
        foreach ($codes as $code) {
            $added += (int) $this->connection->executeStatement(
                'INSERT INTO minimapperz_code (event_id, code, imported_at) VALUES (:event, :code, :now) ON CONFLICT DO NOTHING',
                ['event' => $eventId, 'code' => $code, 'now' => $now->format(\DATE_ATOM)],
            );
        }

        return $added;
    }

    public function codeOf(string $eventId, string $userId): ?string
    {
        $code = $this->connection->fetchOne(
            'SELECT code FROM minimapperz_code WHERE event_id = :event AND user_id = :user',
            ['event' => $eventId, 'user' => $userId],
        );

        return is_string($code) ? $code : null;
    }

    public function assignFreeCode(string $eventId, string $userId, \DateTimeImmutable $now): ?string
    {
        $held = $this->codeOf($eventId, $userId);
        if (null !== $held) {
            return $held;
        }

        try {
            $code = $this->connection->fetchOne(
                'UPDATE minimapperz_code SET user_id = :user, assigned_at = :now
                  WHERE event_id = :event
                    AND code = (SELECT code FROM minimapperz_code
                                 WHERE event_id = :event AND user_id IS NULL
                                 ORDER BY imported_at, code
                                 LIMIT 1 FOR UPDATE SKIP LOCKED)
                 RETURNING code',
                ['event' => $eventId, 'user' => $userId, 'now' => $now->format(\DATE_ATOM)],
            );
        } catch (UniqueConstraintViolationException) {
            // The same member was handed a code by a concurrent request: theirs is the one that won.
            return $this->codeOf($eventId, $userId);
        }

        return is_string($code) ? $code : null;
    }

    public function detach(string $eventId, string $code): bool
    {
        return 1 === (int) $this->connection->executeStatement(
            'UPDATE minimapperz_code SET user_id = NULL, assigned_at = NULL WHERE event_id = :event AND code = :code AND user_id IS NOT NULL',
            ['event' => $eventId, 'code' => $code],
        );
    }
}
