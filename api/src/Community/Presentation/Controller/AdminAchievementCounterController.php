<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Service\AdminAchievementCounterService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 30.57: the admin side of the counters of checks and items that achievements read as `counter:{id}`.
 */
final readonly class AdminAchievementCounterController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminAchievementCounterService $counters,
    ) {
    }

    #[Route('/api/v1/admin/community/achievement-counters', name: 'api_admin_community_achievement_counters', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $dashboard = $this->counters->dashboard();

        return new JsonResponse(['data' => $dashboard['counters'], 'meta' => ['games' => $dashboard['games']]]);
    }

    #[Route('/api/v1/admin/community/achievement-counters/names', name: 'api_admin_community_achievement_counters_names', methods: ['GET'])]
    public function names(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $exclude = [];
        foreach ($request->query->all('exclude') as $name) {
            if (is_string($name)) {
                $exclude[] = $name;
            }
        }
        $result = $this->counters->names(
            $request->query->getString('gameId'),
            $request->query->getString('kind'),
            $request->query->getString('q'),
            $exclude,
        );

        return null === $result
            ? $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable ou sans nom Archipelago.', 404)
            : new JsonResponse(['data' => $result]);
    }

    #[Route('/api/v1/admin/community/achievement-counters', name: 'api_admin_community_achievement_counters_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            return new JsonResponse(['data' => $this->counters->create($this->jsonPayload($request))], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->apiAccessGuard->errorResponse('validation_error', $e->getMessage(), 422);
        }
    }

    #[Route('/api/v1/admin/community/achievement-counters/{id}', name: 'api_admin_community_achievement_counters_update', methods: ['PATCH'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            $result = $this->counters->update($id, $this->jsonPayload($request));
        } catch (\InvalidArgumentException $e) {
            return $this->apiAccessGuard->errorResponse('validation_error', $e->getMessage(), 422);
        }

        return null === $result
            ? $this->apiAccessGuard->errorResponse('not_found', 'Compteur introuvable.', 404)
            : new JsonResponse(['data' => $result]);
    }

    #[Route('/api/v1/admin/community/achievement-counters/{id}', name: 'api_admin_community_achievement_counters_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return match ($this->counters->delete($id)) {
            AdminAchievementCounterService::DELETED => new JsonResponse(null, 204),
            AdminAchievementCounterService::IN_USE => $this->apiAccessGuard->errorResponse('counter_in_use', 'Ce compteur est utilisé par un succès : retire-le du succès avant de le supprimer.', 409),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Compteur introuvable.', 404),
        };
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
