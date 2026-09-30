<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Domain\Enum\CustomImageRefusal;

/**
 * Outcome of a profile image upload (story 30.40): stored (with the URL to preview), refused by the rules, or
 * lost to the storage.
 */
final readonly class CustomImageUpload
{
    private function __construct(
        public ?string $url,
        public ?CustomImageRefusal $refusal,
        public bool $storageUnavailable,
    ) {
    }

    public static function stored(?string $url): self
    {
        return new self($url, null, false);
    }

    public static function refused(CustomImageRefusal $refusal): self
    {
        return new self(null, $refusal, false);
    }

    public static function storageUnavailable(): self
    {
        return new self(null, null, true);
    }
}
