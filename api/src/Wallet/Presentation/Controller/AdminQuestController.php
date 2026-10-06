<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Wallet\Application\Command\ManageQuests;
use App\Wallet\Application\Query\QuestAdminQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The weekly quests, admin side (story 41.15). Failures are typed ApplicationFailures mapped to HTTP by the
 * ApplicationFailureListener (epic 35).
 */
final readonly class AdminQuestController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private QuestAdminQuery $query,
        private ManageQuests $manage,
    ) {
    }

    #[Route('/api/v1/admin/quests', name: 'api_wallet_admin_quests', methods: ['GET'])]
    public function overview(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse($this->query->overview());
    }

    #[Route('/api/v1/admin/quests', name: 'api_wallet_admin_quests_write', methods: ['POST'])]
    public function write(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        if (null === $payload) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $written = $this->manage->write(...$this->terms($payload));

        return new JsonResponse(['id' => $written->id], 201);
    }

    #[Route('/api/v1/admin/quests/{questId}', name: 'api_wallet_admin_quests_edit', methods: ['PATCH'])]
    public function edit(Request $request, string $questId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        if (null === $payload) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $this->manage->edit($questId, ...$this->terms($payload));

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/quests/{questId}/retire', name: 'api_wallet_admin_quests_retire', methods: ['POST'])]
    public function retire(Request $request, string $questId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->retire($questId);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/quests/{questId}/restore', name: 'api_wallet_admin_quests_restore', methods: ['POST'])]
    public function restore(Request $request, string $questId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->restore($questId);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/quests-settings', name: 'api_wallet_admin_quests_settings', methods: ['PUT'])]
    public function settings(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request) ?? [];

        // Story 41.16: either setting may come alone; one given but not a number is refused as out of bounds.
        $this->manage->changeSettings($this->optionalInt($payload, 'questsPerWeek'), $this->optionalInt($payload, 'chestReward'));

        return new JsonResponse(null, 204);
    }

    /** @param array<mixed> $payload */
    private function optionalInt(array $payload, string $key): ?int
    {
        if (!\array_key_exists($key, $payload) || null === $payload[$key]) {
            return null;
        }

        return is_int($payload[$key]) ? $payload[$key] : -1;
    }

    #[Route('/api/v1/admin/quest-weeks/{weekKey}/quests', name: 'api_wallet_admin_quest_weeks_pin', methods: ['POST'])]
    public function pin(Request $request, string $weekKey): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request) ?? [];
        $replaces = $payload['replaces'] ?? null;

        $this->manage->pin(
            $weekKey,
            is_string($payload['questId'] ?? null) ? $payload['questId'] : '',
            is_string($replaces) && '' !== $replaces ? $replaces : null,
        );

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/quest-weeks/{weekKey}/quests/{questId}', name: 'api_wallet_admin_quest_weeks_unpin', methods: ['DELETE'])]
    public function unpin(Request $request, string $weekKey, string $questId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->unpin($weekKey, $questId);

        return new JsonResponse(null, 204);
    }

    /** @return array<mixed>|null */
    private function payload(Request $request): ?array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return array{0: string, 1: string, 2: int, 3: array<mixed>, 4: bool}
     */
    private function terms(array $payload): array
    {
        return [
            is_string($payload['title'] ?? null) ? $payload['title'] : '',
            is_string($payload['description'] ?? null) ? $payload['description'] : '',
            is_int($payload['reward'] ?? null) ? $payload['reward'] : 0,
            is_array($payload['objectives'] ?? null) ? $payload['objectives'] : [],
            true === ($payload['inDraw'] ?? false),
        ];
    }
}
