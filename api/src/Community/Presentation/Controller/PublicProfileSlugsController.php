<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\PublicProfileSlugsQueryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous enumeration of the public-audience profile slugs (story 34.8), consumed by the
 * frontend sitemap to list /joueurs/{slug}. Exposes nothing a crawler could not already see by
 * visiting the profiles - membership-only and friends-only profiles stay out by construction.
 */
final readonly class PublicProfileSlugsController
{
    public function __construct(
        private PublicProfileSlugsQueryInterface $query,
    ) {
    }

    #[Route('/api/v1/community/public-profile-slugs', name: 'api_community_public_profile_slugs', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->all()]);
    }
}
