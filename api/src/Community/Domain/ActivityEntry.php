<?php

declare(strict_types=1);

namespace App\Community\Domain;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only activity feed row (story 30.8). Tagged with the actor only - **no audience column**:
 * visibility is resolved at read time from the actor's *current* profile (epic §H, review #2). The
 * `(actor, type, subjectRef)` unique key makes both the backfill and any live signal idempotent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'community_activity_entry')]
#[ORM\UniqueConstraint(name: 'uniq_community_activity', columns: ['actor_id', 'type', 'subject_ref'])]
#[ORM\Index(name: 'idx_community_activity_actor_time', columns: ['actor_id', 'occurred_at'])]
final class ActivityEntry
{
    public const TYPE_RUN_FINISHED = 'run_finished';
    public const TYPE_FRIENDSHIP = 'friendship';

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'actor_id', type: 'string', length: 32)]
        private string $actorId,
        #[ORM\Column(type: 'string', length: 32)]
        private string $type,
        #[ORM\Column(name: 'subject_ref', type: 'string', length: 191)]
        private string $subjectRef,
        #[ORM\Column(name: 'occurred_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $occurredAt,
        #[ORM\Column(type: Types::JSON)]
        private array $payload = [],
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function record(string $actorId, string $type, string $subjectRef, \DateTimeImmutable $occurredAt, array $payload = []): self
    {
        return new self(bin2hex(random_bytes(16)), $actorId, $type, $subjectRef, $occurredAt, $payload);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getActorId(): string
    {
        return $this->actorId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getSubjectRef(): string
    {
        return $this->subjectRef;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }
}
