<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Story 56.7: a player's search for a size option value that brings their world back to the target duration. One per
 * player and game: a new search replaces the previous one (`round` tells the messages of an older one apart).
 *
 * One size option at a time (the ones an admin designated, in their order). Its minimum is tried first: shorter than
 * the target, the option grows the world, and the search halves the gap between the minimum and the player's value;
 * still too long, its maximum is tried, for an option that shrinks the world as it grows. A try within the tolerance
 * of the target is the proposal; at most `MAX_TRIES` generations in all, then the longest try under the target is
 * proposed, or the search says it found nothing. A generation that fails gives up the option it was trying.
 *
 * Pure: the caller runs each generation and reports its duration. Nothing touches the player's YAML: the proposal
 * waits for their agreement.
 */
#[ORM\Entity]
#[ORM\Table(name: 'size_search')]
final class SizeSearch
{
    public const int MAX_TRIES = 6;
    public const float TOLERANCE = 0.1;

    public const string STATUS_RUNNING = 'running';
    public const string STATUS_FOUND = 'found';
    public const string STATUS_NOT_FOUND = 'not_found';
    public const string STATUS_FAILED = 'failed';

    private const string PHASE_MIN = 'min';
    private const string PHASE_MAX = 'max';
    private const string PHASE_HALVE = 'halve';

    /**
     * @param list<array{key: string, min: int, max: int, current: int}>                      $options
     * @param array{index: int, phase: string, short: int|null, long: int|null}               $plan
     * @param list<array{option: string, value: int, locations: int|null, hours: float|null}> $tries
     * @param array{option: string, value: int, jobId: string}|null                           $pending
     * @param array{option: string, from: int, value: int, hours: float}|null                 $proposal
     */
    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 64)]
        private string $id,
        #[ORM\Column(name: 'user_id', type: 'string', length: 64)]
        private string $userId,
        #[ORM\Column(name: 'game_id', type: 'string', length: 64)]
        private string $gameId,
        #[ORM\Column(type: 'string', length: 32)]
        private string $round,
        #[ORM\Column(type: Types::TEXT)]
        private string $yaml,
        #[ORM\Column(name: 'target_hours', type: 'float')]
        private float $targetHours,
        #[ORM\Column(type: 'string', length: 16)]
        private string $status,
        #[ORM\Column(type: Types::JSON)]
        private array $options,
        #[ORM\Column(type: Types::JSON)]
        private array $plan,
        #[ORM\Column(type: Types::JSON)]
        private array $tries,
        #[ORM\Column(name: 'started_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $startedAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $pending = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        private ?array $proposal = null,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $error = null,
    ) {
    }

    public static function idFor(string $userId, string $gameId): string
    {
        return substr(hash('sha256', $userId.'|'.$gameId), 0, 32);
    }

    /**
     * @param list<array{key: string, min: int, max: int, current: int}> $options the size options, with the player's value
     */
    public static function start(string $userId, string $gameId, string $round, string $yaml, float $targetHours, array $options, \DateTimeImmutable $now): self
    {
        if ([] === $options || $targetHours <= 0) {
            throw new \DomainException('size_search_nothing_to_search');
        }

        return new self(self::idFor($userId, $gameId), $userId, $gameId, $round, $yaml, $targetHours, self::STATUS_RUNNING, $options, self::freshPlan(0), [], $now, $now);
    }

    /**
     * Starts over for a new YAML or target, forgetting the previous search.
     *
     * @param list<array{key: string, min: int, max: int, current: int}> $options
     */
    public function restart(string $round, string $yaml, float $targetHours, array $options, \DateTimeImmutable $now): void
    {
        if ([] === $options || $targetHours <= 0) {
            throw new \DomainException('size_search_nothing_to_search');
        }
        $this->round = $round;
        $this->yaml = $yaml;
        $this->targetHours = $targetHours;
        $this->options = $options;
        $this->status = self::STATUS_RUNNING;
        $this->plan = self::freshPlan(0);
        $this->tries = [];
        $this->pending = null;
        $this->proposal = null;
        $this->error = null;
        $this->startedAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * The next value to generate, or null once the search is over (it then says why). Past the last option or the
     * budget of tries, the search ends without a proposal.
     *
     * @return array{option: string, value: int}|null
     */
    public function nextTry(\DateTimeImmutable $now): ?array
    {
        while (self::STATUS_RUNNING === $this->status) {
            if (count($this->tries) >= self::MAX_TRIES || !isset($this->options[$this->plan['index']])) {
                $this->finishWithBestFit($now);

                return null;
            }
            $option = $this->options[$this->plan['index']];
            $value = match ($this->plan['phase']) {
                self::PHASE_MIN => $option['min'],
                self::PHASE_MAX => $option['max'],
                default => self::middle($this->plan['short'], $this->plan['long']),
            };
            if (null === $value || $value === $option['current'] || $this->tried($option['key'], $value)) {
                $this->advance();
                continue;
            }

            return ['option' => $option['key'], 'value' => $value];
        }

        return null;
    }

    /** A generation was started for this value; its result comes later. */
    public function awaitResult(string $option, int $value, string $jobId, \DateTimeImmutable $now): void
    {
        $this->pending = ['option' => $option, 'value' => $value, 'jobId' => $jobId];
        $this->updatedAt = $now;
    }

    /**
     * The result of the pending generation: its size and estimated duration, or nulls when it failed.
     */
    public function recordResult(?int $locations, ?float $hours, \DateTimeImmutable $now): void
    {
        if (null === $this->pending || self::STATUS_RUNNING !== $this->status) {
            return;
        }
        ['option' => $key, 'value' => $value] = $this->pending;
        $this->pending = null;
        $this->tries[] = ['option' => $key, 'value' => $value, 'locations' => $locations, 'hours' => $hours];
        $this->updatedAt = $now;
        $option = $this->options[$this->plan['index']] ?? null;
        if (null === $option || $option['key'] !== $key) {
            return;
        }

        if (null === $hours) {
            // This value does not generate: the option is left for the next one.
            $this->plan = self::freshPlan($this->plan['index'] + 1);

            return;
        }
        if (abs($hours - $this->targetHours) <= self::TOLERANCE * $this->targetHours) {
            $this->proposal = ['option' => $key, 'from' => $option['current'], 'value' => $value, 'hours' => $hours];
            $this->finish(self::STATUS_FOUND, $now);

            return;
        }

        $short = $hours < $this->targetHours;
        $this->plan = match ($this->plan['phase']) {
            // Shorter at one end: the answer lies between that end and the player's value.
            self::PHASE_MIN => $short ? self::halving($this->plan['index'], $value, $option['current']) : self::phase($this->plan['index'], self::PHASE_MAX),
            self::PHASE_MAX => $short ? self::halving($this->plan['index'], $value, $option['current']) : self::freshPlan($this->plan['index'] + 1),
            default => $short
                ? ['index' => $this->plan['index'], 'phase' => self::PHASE_HALVE, 'short' => $value, 'long' => $this->plan['long']]
                : ['index' => $this->plan['index'], 'phase' => self::PHASE_HALVE, 'short' => $this->plan['short'], 'long' => $value],
        };
    }

    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->pending = null;
        $this->error = $error;
        $this->finish(self::STATUS_FAILED, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getGameId(): string
    {
        return $this->gameId;
    }

    public function getRound(): string
    {
        return $this->round;
    }

    public function getYaml(): string
    {
        return $this->yaml;
    }

    public function getTargetHours(): float
    {
        return $this->targetHours;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isRunning(): bool
    {
        return self::STATUS_RUNNING === $this->status;
    }

    /** @return list<array{key: string, min: int, max: int, current: int}> */
    public function getOptions(): array
    {
        return $this->options;
    }

    /** @return list<array{option: string, value: int, locations: int|null, hours: float|null}> */
    public function getTries(): array
    {
        return $this->tries;
    }

    /** @return array{option: string, value: int, jobId: string}|null */
    public function getPending(): ?array
    {
        return $this->pending;
    }

    /** @return array{option: string, from: int, value: int, hours: float}|null */
    public function getProposal(): ?array
    {
        return $this->proposal;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function advance(): void
    {
        $this->plan = match ($this->plan['phase']) {
            self::PHASE_MIN => self::phase($this->plan['index'], self::PHASE_MAX),
            default => self::freshPlan($this->plan['index'] + 1),
        };
    }

    private function tried(string $key, int $value): bool
    {
        return array_any($this->tries, fn ($try) => $try['option'] === $key && $try['value'] === $value);
    }

    /**
     * Fixed after the 1.1.0 review: no try landed within the tolerance, but one that brings the world under the target
     * still answers the player's question. The longest of those is proposed; without any, the search found nothing.
     */
    private function finishWithBestFit(\DateTimeImmutable $now): void
    {
        $best = null;
        foreach ($this->tries as $try) {
            if (null === $try['hours'] || $try['hours'] > $this->targetHours) {
                continue;
            }
            $option = array_find($this->options, static fn (array $o): bool => $o['key'] === $try['option']);
            if (null === $option || (null !== $best && $best['hours'] >= $try['hours'])) {
                continue;
            }
            $best = ['option' => $try['option'], 'from' => $option['current'], 'value' => $try['value'], 'hours' => $try['hours']];
        }
        if (null === $best) {
            $this->finish(self::STATUS_NOT_FOUND, $now);

            return;
        }
        $this->proposal = $best;
        $this->finish(self::STATUS_FOUND, $now);
    }

    private function finish(string $status, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->updatedAt = $now;
    }

    /** The whole value halfway between two, or null when nothing is left between them. */
    private static function middle(?int $short, ?int $long): ?int
    {
        if (null === $short || null === $long || abs($long - $short) <= 1) {
            return null;
        }

        return intdiv($short + $long, 2);
    }

    /** @return array{index: int, phase: string, short: int|null, long: int|null} */
    private static function freshPlan(int $index): array
    {
        return self::phase($index, self::PHASE_MIN);
    }

    /** @return array{index: int, phase: string, short: int|null, long: int|null} */
    private static function phase(int $index, string $phase): array
    {
        return ['index' => $index, 'phase' => $phase, 'short' => null, 'long' => null];
    }

    /** @return array{index: int, phase: string, short: int|null, long: int|null} */
    private static function halving(int $index, int $short, int $long): array
    {
        return ['index' => $index, 'phase' => self::PHASE_HALVE, 'short' => $short, 'long' => $long];
    }
}
