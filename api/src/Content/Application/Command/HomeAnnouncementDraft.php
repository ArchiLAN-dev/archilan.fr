<?php

declare(strict_types=1);

namespace App\Content\Application\Command;

/** What the admin writes for a home announcement (story 10.14); dates are null when missing or unreadable. */
final readonly class HomeAnnouncementDraft
{
    public function __construct(
        public string $title,
        public string $body,
        public string $ctaLabel,
        public string $ctaUrl,
        public ?\DateTimeImmutable $startsAt,
        public ?\DateTimeImmutable $endsAt,
    ) {
    }
}
