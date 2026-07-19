<?php

declare(strict_types=1);

namespace App\Events\Application\Query;

/**
 * Admin-facing read view of an {@see \App\Events\Domain\Entity\Event}. Produced by {@see AdminEventDrafts}
 * (list/get, and embedded as the `event` of its create/update/transition/configurePrivateAccess outcomes) and
 * returned by the event cover-image / gallery upload commands after they re-derive the media URLs; the admin
 * controllers serialize it verbatim as the `data` payload.
 */
final readonly class AdminEventView
{
    /**
     * @param list<string> $photoGallery
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $description,
        public string $status,
        public string $startsAt,
        public string $endsAt,
        public string $venue,
        public int $capacity,
        public int $confirmedRegistrations,
        public bool $isAtCapacity,
        public string $registrationOpensAt,
        public string $registrationClosesAt,
        public bool $isPublic,
        public string $visibility,
        public bool $hasPrivateAccessPassword,
        public bool $gameSelectionEnabled,
        public ?string $vodUrl,
        public ?string $recapPostSlug,
        public bool $hasRecap,
        public ?string $helloassoFormSlug,
        public ?string $coverImageUrl,
        public ?string $coverImageKey,
        public array $photoGallery,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
