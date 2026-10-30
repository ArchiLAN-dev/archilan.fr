<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

use App\Content\Domain\Entity\Partner;
use App\Content\Domain\Repository\PartnerRepositoryInterface;
use App\Shared\Application\Support\PublicMediaUrlResolver;

/**
 * The partners of the association (story 45.1), in the admin's order. Visitors read only the visible ones (shown, with
 * a logo); the admin reads them all.
 */
final readonly class PartnerCatalogQuery
{
    public function __construct(
        private PartnerRepositoryInterface $partners,
        private PublicMediaUrlResolver $publicMedia,
    ) {
    }

    /** @return list<array{id: string, name: string, description: string, url: string, logoUrl: ?string}> */
    public function forVisitors(): array
    {
        return array_values(array_map(
            $this->view(...),
            array_filter($this->partners->all(), static fn (Partner $partner): bool => $partner->isVisible()),
        ));
    }

    /** @return list<array{id: string, name: string, description: string, url: string, logoUrl: ?string, active: bool, visible: bool}> */
    public function forAdmin(): array
    {
        return array_map(
            fn (Partner $partner): array => [...$this->view($partner), 'active' => $partner->isActive(), 'visible' => $partner->isVisible()],
            $this->partners->all(),
        );
    }

    /** @return array{id: string, name: string, description: string, url: string, logoUrl: ?string} */
    private function view(Partner $partner): array
    {
        $logoKey = $partner->getLogoKey();

        return [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'description' => $partner->getDescription(),
            'url' => $partner->getUrl(),
            'logoUrl' => null === $logoKey ? null : $this->publicMedia->resolve($logoKey),
        ];
    }
}
