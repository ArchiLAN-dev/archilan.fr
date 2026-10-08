<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Service\AdminAchievementCollectionService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 30.52: the admin side of the collections of achievements. The list rides on the achievements dashboard
 * (`GET /api/v1/admin/community/achievements`, `meta.collections`).
 */
final readonly class AdminAchievementCollectionController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminAchievementCollectionService $collections,
    ) {
    }

    #[Route('/api/v1/admin/community/achievement-collections', name: 'api_admin_community_achievement_collections_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            return new JsonResponse(['data' => $this->collections->create($this->jsonPayload($request))], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->apiAccessGuard->errorResponse('validation_error', $e->getMessage(), 422);
        }
    }

    #[Route('/api/v1/admin/community/achievement-collections/reorder', name: 'api_admin_community_achievement_collections_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $rawIds = $this->jsonPayload($request)['ids'] ?? null;
        $ids = [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $id) {
                if (is_string($id)) {
                    $ids[] = $id;
                }
            }
        }
        $this->collections->reorder($ids);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/community/achievement-collections/{id}', name: 'api_admin_community_achievement_collections_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            $result = $this->collections->update($id, $this->jsonPayload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->apiAccessGuard->errorResponse('validation_error', $e->getMessage(), 422);
        }

        return null === $result
            ? $this->apiAccessGuard->errorResponse('not_found', 'Collection introuvable.', 404)
            : new JsonResponse(['data' => $result]);
    }

    #[Route('/api/v1/admin/community/achievement-collections/{id}', name: 'api_admin_community_achievement_collections_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->collections->delete($id)
            ? new JsonResponse(null, 204)
            : $this->apiAccessGuard->errorResponse('not_found', 'Collection introuvable.', 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
