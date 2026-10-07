<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * A personal run one member put away (story 16.21). The archive is personal: each participant - owner or
 * invited - tidies their own list, and the others still see the run. Nothing else changes: the run, its
 * session, recap and statistics stay as they were.
 *
 * Its own table rather than a flag on RunParticipant: the owner only gets a participant row once they pick
 * games, and creating one just to hold the archive would make them count as a player.
 */
#[ORM\Entity]
#[ORM\Table(name: 'personal_run_archive')]
#[ORM\Index(name: 'idx_personal_run_archive_user', columns: ['user_id'])]
final class RunArchive
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'personal_run_id', type: 'string', length: 32)]
        private string $runId,
        #[ORM\Id]
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(name: 'archived_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $archivedAt,
    ) {
    }

    public static function create(string $runId, string $userId, \DateTimeImmutable $now): self
    {
        return new self($runId, $userId, $now);
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getArchivedAt(): \DateTimeImmutable
    {
        return $this->archivedAt;
    }
}
