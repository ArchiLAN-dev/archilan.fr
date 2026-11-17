<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

use App\Content\Domain\Entity\Partner;
use App\Content\Domain\Entity\TrackedLink;
use App\Content\Domain\Repository\PartnerRepositoryInterface;
use App\Content\Domain\Repository\TrackedLinkRepositoryInterface;
use App\Shared\Application\Support\PublicMediaUrlResolver;

/**
 * The partners of the association (story 45.1), in the admin's order. Visitors read only the visible ones (shown, with
 * a logo); the admin reads them all. Story 55.3: a card leads to the partner's tracked link `/l/{code}` (`linkPath`,
 * null when it has none on), and the admin sees what it sent.
 */
final readonly class PartnerCatalogQuery
{
    public function __construct(
        private PartnerRepositoryInterface $partners,
        private PublicMediaUrlResolver $publicMedia,
        private TrackedLinkRepositoryInterface $links,
        private TrackedLinkCatalogQuery $linkCatalog,
    ) {
    }

    /** @return list<array{id: string, name: string, description: string, url: string, logoUrl: ?string, logoOnDark: bool, linkPath: ?string}> */
    public function forVisitors(): array
    {
        $links = $this->linksByPartner();

        return array_values(array_map(
            fn (Partner $partner): array => $this->view($partner, $links[$partner->getId()] ?? null),
            array_filter($this->partners->all(), static fn (Partner $partner): bool => $partner->isVisible()),
        ));
    }

    /** @return list<array{id: string, name: string, description: string, url: string, logoUrl: ?string, logoOnDark: bool, linkPath: ?string, active: bool, visible: bool, visits: array{total: int, last7: int, last30: int}}> */
    public function forAdmin(): array
    {
        $links = $this->linksByPartner();
        $visits = $this->linkCatalog->visitsByLink();

        return array_map(
            function (Partner $partner) use ($links, $visits): array {
                $link = $links[$partner->getId()] ?? null;

                return [
                    ...$this->view($partner, $link),
                    'active' => $partner->isActive(),
                    'visible' => $partner->isVisible(),
                    'visits' => null === $link ? TrackedLinkCatalogQuery::NO_VISITS : ($visits[$link->getId()] ?? TrackedLinkCatalogQuery::NO_VISITS),
                ];
            },
            $this->partners->all(),
        );
    }

    /** @return array{id: string, name: string, description: string, url: string, logoUrl: ?string, logoOnDark: bool, linkPath: ?string} */
    private function view(Partner $partner, ?TrackedLink $link): array
    {
        $logoKey = $partner->getLogoKey();

        return [
            'id' => $partner->getId(),
            'name' => $partner->getName(),
            'description' => $partner->getDescription(),
            'url' => $partner->getUrl(),
            'logoUrl' => null === $logoKey ? null : $this->publicMedia->resolve($logoKey),
            'logoOnDark' => $partner->isLogoOnDark(),
            'linkPath' => null !== $link && $link->isActive() ? '/l/'.$link->getCode() : null,
        ];
    }

    /** @return array<string, TrackedLink> */
    private function linksByPartner(): array
    {
        $links = [];
        foreach ($this->links->all() as $link) {
            $partnerId = $link->getPartnerId();
            if (null !== $partnerId) {
                $links[$partnerId] = $link;
            }
        }

        return $links;
    }
}
