<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Story 56.3: the last dry generation of an event or a private run, and what it measured. One per scope: a new
 * dry generation replaces the previous one.
 *
 * `slots` are the inputs the generation ran on, in the order sent; `fingerprint` sums them up, so the report can
 * tell it no longer matches the registrations. `measures` come from the multidata (story 56.1), matched by name.
 */
#[ORM\Entity]
#[ORM\Table(name: 'balance_report')]
final class BalanceReport
{
    public const string SCOPE_EVENT = 'event';
    public const string SCOPE_RUN = 'run';

    public const string STATUS_GENERATING = 'generating';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';
    /** A world reads a file (a ROM) at generation: nothing was generated. */
    public const string STATUS_NEEDS_FILES = 'needs_files';

    /**
     * @param list<array{slotName: string, playerName: string, userId: string, gameId: string, gameName: string}> $slots
     * @param list<array{name: string, locations: int, ownProgression: int, hostedProgression: int}>|null         $measures
     * @param list<string>                                                                                        $missingFiles
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 80)]
        private string $id,
        #[ORM\Column(name: 'scope_type', type: 'string', length: 16)]
        private string $scopeType,
        #[ORM\Column(name: 'scope_id', type: 'string', length: 32)]
        private string $scopeId,
        #[ORM\Column(type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(name: 'requested_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $requestedAt,
        #[ORM\Column(type: Types::JSON)]
        private array $slots,
        #[ORM\Column(type: 'string', length: 64)]
        private string $fingerprint,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $measures = null,
        #[ORM\Column(name: 'finished_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $finishedAt = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $error = null,
        #[ORM\Column(name: 'output_key', type: 'string', length: 255, nullable: true)]
        private ?string $outputKey = null,
        #[ORM\Column(name: 'missing_files', type: Types::JSON)]
        private array $missingFiles = [],
    ) {
    }

    public static function idFor(string $scopeType, string $scopeId): string
    {
        return $scopeType.'-'.$scopeId;
    }

    /**
     * @param list<array{slotName: string, playerName: string, userId: string, gameId: string, gameName: string}> $slots
     */
    public static function start(string $scopeType, string $scopeId, array $slots, string $fingerprint, \DateTimeImmutable $now): self
    {
        return new self(self::idFor($scopeType, $scopeId), $scopeType, $scopeId, self::STATUS_GENERATING, $now, $slots, $fingerprint);
    }

    /**
     * Starts over on the same scope, forgetting the previous result.
     *
     * @param list<array{slotName: string, playerName: string, userId: string, gameId: string, gameName: string}> $slots
     */
    public function restart(array $slots, string $fingerprint, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_GENERATING;
        $this->requestedAt = $now;
        $this->slots = $slots;
        $this->fingerprint = $fingerprint;
        $this->measures = null;
        $this->finishedAt = null;
        $this->error = null;
        $this->outputKey = null;
        $this->missingFiles = [];
    }

    /** @param list<string> $gameNames */
    public function needFiles(array $gameNames, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_NEEDS_FILES;
        $this->missingFiles = $gameNames;
        $this->finishedAt = $now;
    }

    public function recordOutput(string $outputKey): void
    {
        $this->outputKey = $outputKey;
    }

    /** @param list<array{name: string, locations: int, ownProgression: int, hostedProgression: int}> $measures */
    public function complete(array $measures, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_DONE;
        $this->measures = $measures;
        $this->finishedAt = $now;
        $this->error = null;
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->finishedAt = $now;
    }

    public function isGenerating(): bool
    {
        return self::STATUS_GENERATING === $this->status;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getScopeType(): string
    {
        return $this->scopeType;
    }

    public function getScopeId(): string
    {
        return $this->scopeId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return list<array{slotName: string, playerName: string, userId: string, gameId: string, gameName: string}> */
    public function getSlots(): array
    {
        return $this->slots;
    }

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /** @return list<array{name: string, locations: int, ownProgression: int, hostedProgression: int}>|null */
    public function getMeasures(): ?array
    {
        return $this->measures;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getOutputKey(): ?string
    {
        return $this->outputKey;
    }

    /** @return list<string> */
    public function getMissingFiles(): array
    {
        return $this->missingFiles;
    }
}
