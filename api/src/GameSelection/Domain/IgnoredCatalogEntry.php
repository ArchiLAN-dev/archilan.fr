<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class IgnoredCatalogEntry
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 160)]
        private string $name,
        #[ORM\Column(name: 'ignored_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $ignoredAt,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIgnoredAt(): \DateTimeImmutable
    {
        return $this->ignoredAt;
    }
}
