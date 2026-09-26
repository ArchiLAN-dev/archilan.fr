<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Exception\ApworldCandidateTransitionException;
use Doctrine\ORM\Mapping as ORM;

/**
 * An apworld version waiting for its test verdict before it may serve players (story 38.6).
 *
 * Crystal Project v0.17.0 served players for two months although its test failed on every seed,
 * because an upload replaced the served apworld at once. A candidate is uploaded and tested first;
 * the game only switches to it on promotion. Every candidate is kept as history, which is how a
 * rejected release is never retried automatically.
 *
 * Carries everything the switch needs except the option types and location names: those come from
 * the orchestrator's background introspection, and are read at promotion time, when it is done.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_apworld_candidate_game_status', columns: ['game_id', 'status'])]
#[ORM\Table(name: 'apworld_candidate')]
final class ApworldCandidate
{
    #[ORM\Column(name: 'decided_at', type: 'datetimetz_immutable', nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'forced_by', type: 'string', length: 32, nullable: true)]
    private ?string $forcedBy = null;

    #[ORM\Column(name: 'rejection_reason', type: 'text', nullable: true)]
    private ?string $rejectionReason = null;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'apworld_hash', type: 'string', length: 64)]
        private string $apworldHash,
        #[ORM\Column(name: 'storage_key', type: 'string', length: 255)]
        private string $storageKey,
        #[ORM\Column(name: 'minio_key', type: 'string', length: 255)]
        private string $minioKey,
        #[ORM\Column(name: 'default_yaml', type: 'text')]
        private string $defaultYaml,
        #[ORM\Column(name: 'archipelago_game_name', type: 'string', length: 255)]
        private string $archipelagoGameName,
        #[ORM\Column(name: 'version_tag', type: 'string', length: 100, nullable: true)]
        private ?string $versionTag,
        #[ORM\Column(type: 'string', length: 16, enumType: ApworldCandidateOrigin::class)]
        private ApworldCandidateOrigin $origin,
        #[ORM\Column(name: 'submitted_by', type: 'string', length: 32, nullable: true)]
        private ?string $submittedBy,
        #[ORM\Column(name: 'submitted_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $submittedAt,
        #[ORM\Column(type: 'string', length: 16, enumType: ApworldCandidateStatus::class)]
        private ApworldCandidateStatus $status,
    ) {
    }

    public static function submit(
        string $id,
        string $gameId,
        string $apworldHash,
        string $storageKey,
        string $minioKey,
        string $defaultYaml,
        string $archipelagoGameName,
        ?string $versionTag,
        ApworldCandidateOrigin $origin,
        ?string $submittedBy,
        \DateTimeImmutable $now,
    ): self {
        return new self($id, $gameId, $apworldHash, $storageKey, $minioKey, $defaultYaml, $archipelagoGameName, $versionTag, $origin, $submittedBy, $now, ApworldCandidateStatus::Testing);
    }

    /**
     * The candidate becomes the apworld the game serves. From the test (verdict passed) or, forced by
     * an admin, from the test or from a rejection. Never from a superseded or promoted candidate.
     */
    public function promote(\DateTimeImmutable $now, ?string $forcedBy): void
    {
        $this->assertStatus([ApworldCandidateStatus::Testing, ApworldCandidateStatus::Rejected, ApworldCandidateStatus::Expired], 'promoted');

        $this->status = ApworldCandidateStatus::Promoted;
        $this->decidedAt = $now;
        $this->forcedBy = $forcedBy;
        $this->rejectionReason = null;
    }

    public function reject(string $reason, \DateTimeImmutable $now): void
    {
        $this->assertStatus([ApworldCandidateStatus::Testing], 'rejected');

        $this->status = ApworldCandidateStatus::Rejected;
        $this->decidedAt = $now;
        $this->rejectionReason = $reason;
    }

    /**
     * No verdict within the deadline: the test did not conclude, the release is not judged.
     */
    public function expire(string $reason, \DateTimeImmutable $now): void
    {
        $this->assertStatus([ApworldCandidateStatus::Testing], 'expired');

        $this->status = ApworldCandidateStatus::Expired;
        $this->decidedAt = $now;
        $this->rejectionReason = $reason;
    }

    /**
     * An admin asks for the test again, typically after a transient failure. The deadline restarts.
     */
    public function retry(\DateTimeImmutable $now): void
    {
        $this->assertStatus([ApworldCandidateStatus::Rejected, ApworldCandidateStatus::Expired], 'retried');

        $this->status = ApworldCandidateStatus::Testing;
        $this->submittedAt = $now;
        $this->decidedAt = null;
        $this->rejectionReason = null;
    }

    public function supersede(\DateTimeImmutable $now): void
    {
        $this->assertStatus([ApworldCandidateStatus::Testing], 'superseded');

        $this->status = ApworldCandidateStatus::Superseded;
        $this->decidedAt = $now;
    }

    public function hasTestTimedOut(\DateTimeImmutable $now, \DateInterval $timeout): bool
    {
        return ApworldCandidateStatus::Testing === $this->status && $now >= $this->submittedAt->add($timeout);
    }

    /**
     * @param list<ApworldCandidateStatus> $allowed
     */
    private function assertStatus(array $allowed, string $transition): void
    {
        if (!\in_array($this->status, $allowed, true)) {
            throw ApworldCandidateTransitionException::from($this->id, $this->status, $transition);
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

    public function getStorageKey(): string
    {
        return $this->storageKey;
    }

    public function getMinioKey(): string
    {
        return $this->minioKey;
    }

    public function getDefaultYaml(): string
    {
        return $this->defaultYaml;
    }

    public function getArchipelagoGameName(): string
    {
        return $this->archipelagoGameName;
    }

    public function getVersionTag(): ?string
    {
        return $this->versionTag;
    }

    public function getOrigin(): ApworldCandidateOrigin
    {
        return $this->origin;
    }

    public function getSubmittedBy(): ?string
    {
        return $this->submittedBy;
    }

    public function getSubmittedAt(): \DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getStatus(): ApworldCandidateStatus
    {
        return $this->status;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function getForcedBy(): ?string
    {
        return $this->forcedBy;
    }

    public function getRejectionReason(): ?string
    {
        return $this->rejectionReason;
    }
}
