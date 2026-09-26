<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use App\GameSelection\Domain\Enum\ApworldIncidentStatus;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\GameSelection\Domain\Exception\ApworldIncidentTransitionException;
use Doctrine\ORM\Mapping as ORM;

/**
 * A problem with an apworld that an admin has to see and follow (story 38.1).
 *
 * Born from the Crystal Project v0.17.0 case: its test generation failed on every seed for two
 * months, and the verdict only ever showed up as a badge on the game's admin page. An incident
 * gives that verdict a memory, an owner and an end.
 *
 * Pure: the id and every date come in as parameters (no clock, no randomness in the domain).
 * At most one active incident per (game, apworld hash, type), enforced by a partial unique index
 * so that two concurrent reconciliations cannot both open one. Its `where` is written the way
 * PostgreSQL normalizes it, so `doctrine:migrations:diff` sees no change (same as Membership).
 */
#[ORM\Entity]
#[ORM\Table(name: 'apworld_incident')]
#[ORM\Index(name: 'idx_apworld_incident_status', columns: ['status'])]
#[ORM\UniqueConstraint(
    name: 'uniq_apworld_incident_active_key',
    columns: ['game_id', 'apworld_hash', 'type'],
    options: ['where' => "((status)::text = ANY ((ARRAY['open'::character varying, 'acknowledged'::character varying])::text[]))"],
)]
final class ApworldIncident
{
    #[ORM\Column(name: 'acknowledged_by', type: 'string', length: 32, nullable: true)]
    private ?string $acknowledgedBy = null;

    #[ORM\Column(name: 'acknowledged_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(name: 'closed_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(name: 'closed_by', type: 'string', length: 32, nullable: true)]
    private ?string $closedBy = null;

    /**
     * What identifies the last observation of the problem, when the source has one: the checkedAt of
     * the test verdict. The same verdict read again is not a new failure (story 38.1 review).
     */
    #[ORM\Column(name: 'last_observation', type: 'string', length: 64, nullable: true)]
    private ?string $lastObservation = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'apworld_hash', type: 'string', length: 64)]
        private string $apworldHash,
        #[ORM\Column(type: 'string', length: 32, enumType: ApworldIncidentType::class)]
        private ApworldIncidentType $type,
        #[ORM\Column(type: 'string', length: 16, enumType: ApworldIncidentStatus::class)]
        private ApworldIncidentStatus $status,
        #[ORM\Column(type: 'text')]
        private string $error,
        #[ORM\Column(name: 'opened_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $openedAt,
        #[ORM\Column(name: 'last_seen_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $lastSeenAt,
        #[ORM\Column(type: 'integer')]
        private int $occurrences,
    ) {
    }

    public static function open(
        string $id,
        string $gameId,
        string $apworldHash,
        ApworldIncidentType $type,
        string $error,
        \DateTimeImmutable $now,
        ?string $observation = null,
    ): self {
        $incident = new self($id, $gameId, $apworldHash, $type, ApworldIncidentStatus::Open, $error, $now, $now, 1);
        $incident->lastObservation = $observation;

        return $incident;
    }

    /**
     * The same problem seen again: one incident, a growing count, never a second alert. An observation
     * already counted (the same verdict read again) changes nothing: false.
     */
    public function recordRecurrence(string $error, \DateTimeImmutable $now, ?string $observation = null): bool
    {
        $this->assertActive('recorded again');
        if ($this->isObservation($observation)) {
            return false;
        }

        $this->error = $error;
        $this->lastSeenAt = $now;
        $this->lastObservation = $observation;
        ++$this->occurrences;

        return true;
    }

    /** Whether this observation is the one the incident last saw. Null never is: each report counts. */
    public function isObservation(?string $observation): bool
    {
        return null !== $observation && $observation === $this->lastObservation;
    }

    /**
     * An admin takes the incident. Taking an incident someone else already holds is a handover,
     * not an error: the latest admin is the one to ask.
     */
    public function acknowledge(string $userId, \DateTimeImmutable $now): void
    {
        $this->assertActive('acknowledged');

        $this->status = ApworldIncidentStatus::Acknowledged;
        $this->acknowledgedBy = $userId;
        $this->acknowledgedAt = $now;
    }

    /**
     * A null user means the reconciliation closed it: the verdict turned green or the game stopped
     * serving this apworld.
     */
    public function resolve(\DateTimeImmutable $now, ?string $userId): void
    {
        $this->close(ApworldIncidentStatus::Resolved, $now, $userId, 'resolved');
    }

    /**
     * Ignoring holds for this apworld hash only: the reconciliation never reopens an ignored
     * incident for the same key. A null user means an admin override on the verdict did it.
     */
    public function ignore(\DateTimeImmutable $now, ?string $userId): void
    {
        $this->close(ApworldIncidentStatus::Ignored, $now, $userId, 'ignored');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function wasClosedAutomatically(): bool
    {
        return null !== $this->closedAt && null === $this->closedBy;
    }

    private function close(ApworldIncidentStatus $status, \DateTimeImmutable $now, ?string $userId, string $transition): void
    {
        $this->assertActive($transition);

        $this->status = $status;
        $this->closedAt = $now;
        $this->closedBy = $userId;
    }

    private function assertActive(string $transition): void
    {
        if (!$this->status->isActive()) {
            throw ApworldIncidentTransitionException::notActive($this->id, $this->status, $transition);
        }
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getApworldHash(): string
    {
        return $this->apworldHash;
    }

    public function getType(): ApworldIncidentType
    {
        return $this->type;
    }

    public function getStatus(): ApworldIncidentStatus
    {
        return $this->status;
    }

    public function getError(): string
    {
        return $this->error;
    }

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getOccurrences(): int
    {
        return $this->occurrences;
    }

    public function getAcknowledgedBy(): ?string
    {
        return $this->acknowledgedBy;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?string
    {
        return $this->closedBy;
    }
}
