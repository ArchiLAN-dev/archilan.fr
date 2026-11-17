<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;

/** The tracked links for the admin (story 55.1), newest first. Their counts come with story 55.2. */
final readonly class TrackedLinkCatalogQuery
{
    public function __construct(private TrackedLinkRepositoryInterface $links)
    {
    }

    /** @return list<array{id: string, code: string, label: string, target: string, direction: 'inbound'|'outbound', active: bool, createdAt: string}> */
    public function forAdmin(): array
    {
        return array_map(static fn (TrackedLink $link): array => [
            'id' => $link->getId(),
            'code' => $link->getCode(),
            'label' => $link->getLabel(),
            'target' => $link->getTarget(),
            'direction' => $link->isOutbound() ? 'outbound' : 'inbound',
            'active' => $link->isActive(),
            'createdAt' => $link->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->links->all());
    }
}
