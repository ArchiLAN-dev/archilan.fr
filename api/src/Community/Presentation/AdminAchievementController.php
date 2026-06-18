<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\AdminAchievementService;
use App\Community\Domain\InvalidAchievementRuleException;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin CRUD for configurable achievement definitions (story 30.16). Deserialize → validate via the
 * application service → serialize; 422 on a malformed key or rule tree.
 */
final readonly class AdminAchievementController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminAchievementService $achievements,
    ) {
    }

    #[Route('/api/v1/admin/community/achievements', name: 'api_admin_community_achievements', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $dashboard = $this->achievements->dashboard();

        return new JsonResponse(['data' => $dashboard['definitions'], 'meta' => ['options' => $dashboard['options']]]);
    }

    #[Route('/api/v1/admin/community/achievements', name: 'api_admin_community_achievements_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            return new JsonResponse(['data' => $this->achievements->create($this->jsonPayload($request))], 201);
        } catch (InvalidAchievementRuleException|\InvalidArgumentException $e) {
            return $this->invalid($e);
        }
    }

    #[Route('/api/v1/admin/community/achievements/{id}', name: 'api_admin_community_achievements_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            $result = $this->achievements->update($id, $this->jsonPayload($request));
        } catch (InvalidAchievementRuleException|\InvalidArgumentException $e) {
            return $this->invalid($e);
        }

        return null === $result
            ? $this->apiAccessGuard->errorResponse('not_found', 'Succès introuvable.', 404)
            : new JsonResponse(['data' => $result]);
    }

    #[Route('/api/v1/admin/community/achievements/{id}/active', name: 'api_admin_community_achievements_active', methods: ['POST'])]
    public function setActive(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $active = (bool) ($this->jsonPayload($request)['active'] ?? false);

        return $this->achievements->setActive($id, $active)
            ? new JsonResponse(null, 204)
            : $this->apiAccessGuard->errorResponse('not_found', 'Succès introuvable.', 404);
    }

    #[Route('/api/v1/admin/community/achievements/reorder', name: 'api_admin_community_achievements_reorder', methods: ['POST'])]
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

        $this->achievements->reorder($ids);

        return new JsonResponse(null, 204);
    }

    private function invalid(\Throwable $e): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('validation_error', $e->getMessage(), 422);
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
