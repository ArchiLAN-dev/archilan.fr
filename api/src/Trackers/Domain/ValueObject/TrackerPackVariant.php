<?php

declare(strict_types=1);

namespace App\Trackers\Domain\ValueObject;

/** One variant of a pack, as its manifest declares it (story 54.1). */
final readonly class TrackerPackVariant
{
    /** The flag a variant carries when it follows an Archipelago room. */
    public const string ARCHIPELAGO_FLAG = 'ap';

    /** @param list<string> $flags */
    public function __construct(
        public string $uid,
        public string $displayName,
        public array $flags,
    ) {
    }

    public function supportsArchipelago(): bool
    {
        return in_array(self::ARCHIPELAGO_FLAG, $this->flags, true);
    }

    /** @return array{uid: string, displayName: string, flags: list<string>} */
    public function toArray(): array
    {
        return ['uid' => $this->uid, 'displayName' => $this->displayName, 'flags' => $this->flags];
    }
}
