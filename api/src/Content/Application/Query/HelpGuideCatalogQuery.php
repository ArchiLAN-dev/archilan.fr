<?php

declare(strict_types=1);

namespace App\Content\Application\Query;

use App\Content\Domain\Entity\HelpGuide;
use App\Content\Domain\Repository\HelpGuideRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;

/**
 * The help guides (story 53.5), in the admin's order. Visitors read the published ones only; a draft is a 404 for
 * them, the admin reads everything.
 */
final readonly class HelpGuideCatalogQuery
{
    public function __construct(private HelpGuideRepositoryInterface $guides)
    {
    }

    /** @return list<array{slug: string, title: string, summary: string}> */
    public function published(): array
    {
        $cards = [];
        foreach ($this->guides->all() as $guide) {
            if ($guide->isPublished()) {
                $cards[] = ['slug' => $guide->getSlug(), 'title' => $guide->getTitle(), 'summary' => $guide->getSummary()];
            }
        }

        return $cards;
    }

    /**
     * @return array{slug: string, title: string, summary: string, body: string, updatedAt: string}
     *
     * @throws NotFoundException when no published guide has the slug
     */
    public function publishedBySlug(string $slug): array
    {
        $guide = $this->guides->findBySlug($slug);
        if (!$guide instanceof HelpGuide || !$guide->isPublished()) {
            throw new NotFoundException('Guide introuvable.', 'help_guide_not_found');
        }

        return [
            'slug' => $guide->getSlug(),
            'title' => $guide->getTitle(),
            'summary' => $guide->getSummary(),
            'body' => $guide->getBody(),
            'updatedAt' => $guide->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return list<array{id: string, slug: string, title: string, summary: string, body: string, published: bool, updatedAt: string}> */
    public function forAdmin(): array
    {
        return array_map(static fn (HelpGuide $guide): array => [
            'id' => $guide->getId(),
            'slug' => $guide->getSlug(),
            'title' => $guide->getTitle(),
            'summary' => $guide->getSummary(),
            'body' => $guide->getBody(),
            'published' => $guide->isPublished(),
            'updatedAt' => $guide->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ], $this->guides->all());
    }
}
