<?php

declare(strict_types=1);

namespace App\Identity\Application;

final class RotationResult
{
    private function __construct(
        public readonly string $outcome,
        public readonly ?string $userId,
        public readonly ?string $rawRefreshToken,
        public readonly bool $rememberMe = true,
    ) {
    }

    public static function rotated(string $userId, string $rawRefreshToken, bool $rememberMe = true): self
    {
        return new self('rotated', $userId, $rawRefreshToken, $rememberMe);
    }

    public static function invalid(): self
    {
        return new self('invalid', null, null);
    }

    public static function reuseDetected(string $userId): self
    {
        return new self('reuse_detected', $userId, null);
    }
}
