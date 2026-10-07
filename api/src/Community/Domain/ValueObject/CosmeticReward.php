<?php

declare(strict_types=1);

namespace App\Community\Domain\ValueObject;

/**
 * A cosmetic an achievement or a quest unlocks (story 41.28): a frame, a banner, a title or a name colour, by its
 * key. Whether the key exists is the catalog's call, not this value's.
 */
final readonly class CosmeticReward
{
    public const string FRAME = 'frame';
    public const string BANNER = 'banner';
    public const string TITLE = 'title';
    public const string COLOR = 'color';
    public const array TYPES = [self::FRAME, self::BANNER, self::TITLE, self::COLOR];

    private function __construct(
        public string $type,
        public string $key,
    ) {
    }

    /**
     * The reward a form or a row carries: none when both parts are empty.
     *
     * @throws \DomainException when only one part is given, or the type is unknown
     */
    public static function fromParts(mixed $type, mixed $key): ?self
    {
        $type = is_string($type) ? trim($type) : '';
        $key = is_string($key) ? trim($key) : '';
        if ('' === $type && '' === $key) {
            return null;
        }
        if (!in_array($type, self::TYPES, true) || '' === $key || mb_strlen($key) > 64) {
            throw new \DomainException('cosmetic_reward_invalid');
        }

        return new self($type, $key);
    }

    public function equals(?self $other): bool
    {
        return null !== $other && $other->type === $this->type && $other->key === $this->key;
    }

    /** @return array{type: string, key: string} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'key' => $this->key];
    }
}
