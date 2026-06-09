<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

/**
 * A partial, per-field override of a {@see SessionConfig}. Every field is nullable;
 * a null field means "inherit the type profile". Applied via SessionConfig::withOverride.
 */
final readonly class SessionConfigOverride
{
    /**
     * @param ?list<PlandoOption> $plandoOptions
     */
    public function __construct(
        public ?ReleaseCollectMode $releaseMode = null,
        public ?ReleaseCollectMode $collectMode = null,
        public ?RemainingMode $remainingMode = null,
        public ?bool $disableItemCheat = null,
        public ?int $hintCost = null,
        public ?int $locationCheckPoints = null,
        public ?CountdownMode $countdownMode = null,
        public ?int $autoShutdown = null,
        public ?Compatibility $compatibility = null,
        public ?string $joinPassword = null,
        public ?array $plandoOptions = null,
        public ?bool $race = null,
        public ?SpoilerLevel $spoiler = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return null === $this->releaseMode
            && null === $this->collectMode
            && null === $this->remainingMode
            && null === $this->disableItemCheat
            && null === $this->hintCost
            && null === $this->locationCheckPoints
            && null === $this->countdownMode
            && null === $this->autoShutdown
            && null === $this->compatibility
            && null === $this->joinPassword
            && null === $this->plandoOptions
            && null === $this->race
            && null === $this->spoiler;
    }
}
