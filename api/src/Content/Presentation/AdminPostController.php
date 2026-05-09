<?php

declare(strict_types=1);

namespace App\Content\Presentation;

use App\Content\Application\AdminPostCatalog;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminPostController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminPostCatalog $adminPostCatalog,
    ) {
    }

    #[Route('/api/v1/admin/posts', name: 'api_content_admin_posts_list', methods: ['GET'])]
    public function adminList(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->adminPostCatalog->list(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/posts', name: 'api_content_admin_posts_create', methods: ['POST'])]
    public function adminCreate(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminPostCatalog->create($this->jsonPayload($request), new \DateTimeImmutable());

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'L\'article contient des erreurs.', 422, $result['errors']);
        }

        $id = $result['id'] ?? null;
        if (null === $id) {
            return $this->apiAccessGuard->errorResponse('post_creation_failed', 'La création de l\'article a échoué.', 500);
        }

        $post = $this->adminPostCatalog->get($id);

        return new JsonResponse(['data' => $post, 'meta' => ['message' => 'Article créé.']], 201);
    }

    #[Route('/api/v1/admin/posts/{id}', name: 'api_content_admin_posts_show', methods: ['GET'])]
    public function adminShow(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $post = $this->adminPostCatalog->get($id);

        if (null === $post) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Article introuvable.', 404);
        }

        return new JsonResponse(['data' => $post, 'meta' => []]);
    }

    #[Route('/api/v1/admin/posts/{id}', name: 'api_content_admin_posts_update', methods: ['PATCH'])]
    public function adminUpdate(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminPostCatalog->update($id, $this->jsonPayload($request), new \DateTimeImmutable());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Article introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'L\'article contient des erreurs.', 422, $result['errors']);
        }

        $post = $this->adminPostCatalog->get($id);

        return new JsonResponse(['data' => $post, 'meta' => ['message' => 'Article mis à jour.']]);
    }

    #[Route('/api/v1/admin/posts/{id}/publish', name: 'api_content_admin_posts_publish', methods: ['POST'])]
    public function adminPublish(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminPostCatalog->publish($id, new \DateTimeImmutable());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Article introuvable.', 404);
        }

        $post = $this->adminPostCatalog->get($id);

        return new JsonResponse(['data' => $post, 'meta' => ['message' => 'Article publié.']]);
    }

    #[Route('/api/v1/admin/posts/{id}/unpublish', name: 'api_content_admin_posts_unpublish', methods: ['POST'])]
    public function adminUnpublish(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminPostCatalog->unpublish($id, new \DateTimeImmutable());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Article introuvable.', 404);
        }

        $post = $this->adminPostCatalog->get($id);

        return new JsonResponse(['data' => $post, 'meta' => ['message' => 'Article dépublié.']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $result = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
