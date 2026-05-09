<?php

declare(strict_types=1);

namespace App\Content\Presentation;

use App\Content\Application\PublicPostCatalog;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PostController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PublicPostCatalog $publicPostCatalog,
    ) {
    }

    #[Route('/api/v1/posts', name: 'api_posts_public_list', methods: ['GET'])]
    public function publicList(): JsonResponse
    {
        return new JsonResponse(['data' => $this->publicPostCatalog->list(), 'meta' => []]);
    }

    #[Route('/api/v1/posts/{slug}', name: 'api_posts_public_show', methods: ['GET'])]
    public function publicShow(string $slug): JsonResponse
    {
        $post = $this->publicPostCatalog->get($slug);

        if (null === $post) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Article introuvable.', 404);
        }

        return new JsonResponse(['data' => $post, 'meta' => []]);
    }
}
