<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * What the test verdicts of one apworld have shown over time (story 38.9): its last status, its last
 * success and the image it passed on, and how many failures in a row since.
 *
 * The rolling sweep reruns tests that used to pass. A failure there is confirmed before alerting - a
 * seed can be unlucky - and a hash that passed on one image and keeps failing on the next one is an
 * image regression, which the incident says. A verdict is identified by its `checkedAt`, read again
 * every five minutes by the reconciliation: the same one is never counted twice.
 *
 * Pure: the id comes in as a parameter, the dates are the orchestrator's.
 */
#[ORM\Entity]
#[ORM\Table(name: 'apworld_health')]
#[ORM\UniqueConstraint(name: 'uniq_apworld_health_game_hash', columns: ['game_id', 'apworld_hash'])]
final class ApworldHealth
{
    public const string STATUS_PASSED = 'passed';
    public const string STATUS_FAILED = 'failed';

    #[ORM\Column(name: 'last_status', type: 'string', length: 16, nullable: true)]
    private ?string $lastStatus = null;

    #[ORM\Column(name: 'last_verdict_at', type: 'string', length: 64, nullable: true)]
    private ?string $lastVerdictAt = null;

    #[ORM\Column(name: 'last_success_at', type: 'string', length: 64, nullable: true)]
    private ?string $lastSuccessAt = null;

    #[ORM\Column(name: 'last_success_image', type: 'string', length: 255, nullable: true)]
    private ?string $lastSuccessImage = null;

    #[ORM\Column(name: 'last_success_image_id', type: 'string', length: 100, nullable: true)]
    private ?string $lastSuccessImageId = null;

    #[ORM\Column(name: 'consecutive_failures', type: 'integer')]
    private int $consecutiveFailures = 0;

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'game_id', type: 'string', length: 32)]
        private string $gameId,
        #[ORM\Column(name: 'apworld_hash', type: 'string', length: 64)]
        private string $apworldHash,
    ) {
    }

    public static function start(string $id, string $gameId, string $apworldHash): self
    {
        return new self($id, $gameId, $apworldHash);
    }

    /**
     * Records a completed verdict (passed or failed). False when it is the one already recorded.
     *
     * @param string $checkedAt the verdict's own date, which identifies it
     */
    public function recordVerdict(string $status, string $checkedAt, ?string $image, ?string $imageId): bool
    {
        if ($checkedAt === $this->lastVerdictAt) {
            return false;
        }

        $this->lastStatus = $status;
        $this->lastVerdictAt = $checkedAt;
        if (self::STATUS_PASSED === $status) {
            $this->consecutiveFailures = 0;
            $this->lastSuccessAt = $checkedAt;
            $this->lastSuccessImage = $image;
            $this->lastSuccessImageId = $imageId;
        } else {
            ++$this->consecutiveFailures;
        }

        return true;
    }

    public function hasPassedBefore(): bool
    {
        return null !== $this->lastSuccessAt;
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

    public function getConsecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function getLastStatus(): ?string
    {
        return $this->lastStatus;
    }

    public function getLastSuccessAt(): ?string
    {
        return $this->lastSuccessAt;
    }

    public function getLastSuccessImage(): ?string
    {
        return $this->lastSuccessImage;
    }

    public function getLastSuccessImageId(): ?string
    {
        return $this->lastSuccessImageId;
    }
}
