<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain\ValueObject;

use App\SessionConfig\Domain\Enum\Compatibility;
use App\SessionConfig\Domain\Enum\CountdownMode;
use App\SessionConfig\Domain\Enum\PlandoOption;
use App\SessionConfig\Domain\Enum\ReleaseCollectMode;
use App\SessionConfig\Domain\Enum\RemainingMode;
use App\SessionConfig\Domain\Enum\SpoilerLevel;

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
        public ?bool $pelleHints = null,
        public ?int $pelleItemHintPrice = null,
        public ?int $pelleLocationHintPrice = null,
        public ?bool $pelleBounties = null,
    ) {
    }

    /**
     * Full override capturing every field of a resolved config - used to snapshot the
     * effective config for a session so a restart reuses it (see SessionConfigResolver).
     */
    public static function fromConfig(SessionConfig $c): self
    {
        return new self(
            releaseMode: $c->server->releaseMode,
            collectMode: $c->server->collectMode,
            remainingMode: $c->server->remainingMode,
            disableItemCheat: $c->server->disableItemCheat,
            hintCost: $c->server->hintCost,
            locationCheckPoints: $c->server->locationCheckPoints,
            countdownMode: $c->server->countdownMode,
            autoShutdown: $c->server->autoShutdown,
            compatibility: $c->server->compatibility,
            joinPassword: $c->server->joinPassword,
            plandoOptions: $c->generation->plandoOptions,
            race: $c->generation->race,
            spoiler: $c->generation->spoiler,
            pelleHints: $c->server->pelleHints,
            pelleItemHintPrice: $c->server->pelleItemHintPrice,
            pelleLocationHintPrice: $c->server->pelleLocationHintPrice,
            pelleBounties: $c->server->pelleBounties,
        );
    }

    /**
     * Canonical array form for JSON storage. Only set (non-null) fields are emitted.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [];
        if (null !== $this->releaseMode) {
            $out['releaseMode'] = $this->releaseMode->value;
        }
        if (null !== $this->collectMode) {
            $out['collectMode'] = $this->collectMode->value;
        }
        if (null !== $this->remainingMode) {
            $out['remainingMode'] = $this->remainingMode->value;
        }
        if (null !== $this->disableItemCheat) {
            $out['disableItemCheat'] = $this->disableItemCheat;
        }
        if (null !== $this->hintCost) {
            $out['hintCost'] = $this->hintCost;
        }
        if (null !== $this->locationCheckPoints) {
            $out['locationCheckPoints'] = $this->locationCheckPoints;
        }
        if (null !== $this->countdownMode) {
            $out['countdownMode'] = $this->countdownMode->value;
        }
        if (null !== $this->autoShutdown) {
            $out['autoShutdown'] = $this->autoShutdown;
        }
        if (null !== $this->compatibility) {
            $out['compatibility'] = $this->compatibility->value;
        }
        if (null !== $this->joinPassword) {
            $out['joinPassword'] = $this->joinPassword;
        }
        if (null !== $this->plandoOptions) {
            $out['plandoOptions'] = array_map(static fn (PlandoOption $o): string => $o->value, $this->plandoOptions);
        }
        if (null !== $this->race) {
            $out['race'] = $this->race;
        }
        if (null !== $this->spoiler) {
            $out['spoiler'] = $this->spoiler->value;
        }
        if (null !== $this->pelleHints) {
            $out['pelleHints'] = $this->pelleHints;
        }
        if (null !== $this->pelleItemHintPrice) {
            $out['pelleItemHintPrice'] = $this->pelleItemHintPrice;
        }
        if (null !== $this->pelleLocationHintPrice) {
            $out['pelleLocationHintPrice'] = $this->pelleLocationHintPrice;
        }
        if (null !== $this->pelleBounties) {
            $out['pelleBounties'] = $this->pelleBounties;
        }

        return $out;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $plando = null;
        if (isset($data['plandoOptions'])) {
            if (!is_array($data['plandoOptions'])) {
                throw new \DomainException('invalid_plando_option');
            }
            $plando = [];
            foreach ($data['plandoOptions'] as $p) {
                if (!is_string($p)) {
                    throw new \DomainException('invalid_plando_option');
                }
                $plando[] = PlandoOption::fromString($p);
            }
        }

        return new self(
            releaseMode: isset($data['releaseMode']) && is_string($data['releaseMode']) ? ReleaseCollectMode::fromString($data['releaseMode']) : null,
            collectMode: isset($data['collectMode']) && is_string($data['collectMode']) ? ReleaseCollectMode::fromString($data['collectMode']) : null,
            remainingMode: isset($data['remainingMode']) && is_string($data['remainingMode']) ? RemainingMode::fromString($data['remainingMode']) : null,
            disableItemCheat: isset($data['disableItemCheat']) && is_bool($data['disableItemCheat']) ? $data['disableItemCheat'] : null,
            hintCost: isset($data['hintCost']) && is_int($data['hintCost']) ? $data['hintCost'] : null,
            locationCheckPoints: isset($data['locationCheckPoints']) && is_int($data['locationCheckPoints']) ? $data['locationCheckPoints'] : null,
            countdownMode: isset($data['countdownMode']) && is_string($data['countdownMode']) ? CountdownMode::fromString($data['countdownMode']) : null,
            autoShutdown: isset($data['autoShutdown']) && is_int($data['autoShutdown']) ? $data['autoShutdown'] : null,
            compatibility: isset($data['compatibility']) && is_int($data['compatibility']) ? Compatibility::fromInt($data['compatibility']) : null,
            joinPassword: isset($data['joinPassword']) && is_string($data['joinPassword']) ? $data['joinPassword'] : null,
            plandoOptions: $plando,
            race: isset($data['race']) && is_bool($data['race']) ? $data['race'] : null,
            spoiler: isset($data['spoiler']) && is_int($data['spoiler']) ? SpoilerLevel::fromInt($data['spoiler']) : null,
            pelleHints: isset($data['pelleHints']) && is_bool($data['pelleHints']) ? $data['pelleHints'] : null,
            pelleItemHintPrice: isset($data['pelleItemHintPrice']) && is_int($data['pelleItemHintPrice']) ? $data['pelleItemHintPrice'] : null,
            pelleLocationHintPrice: isset($data['pelleLocationHintPrice']) && is_int($data['pelleLocationHintPrice']) ? $data['pelleLocationHintPrice'] : null,
            pelleBounties: isset($data['pelleBounties']) && is_bool($data['pelleBounties']) ? $data['pelleBounties'] : null,
        );
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
            && null === $this->spoiler
            && null === $this->pelleHints
            && null === $this->pelleItemHintPrice
            && null === $this->pelleLocationHintPrice
            && null === $this->pelleBounties;
    }
}
