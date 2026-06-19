<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\AdminGameContributionsQueryInterface;
use App\GameSelection\Application\ModerateGameTutorialContribution;
use App\GameSelection\Domain\GameTutorialContribution;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminGameContributionController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminGameContributionsQueryInterface $query,
        private ModerateGameTutorialContribution $moderate,
    ) {
    }

    #[Route('/api/v1/admin/game-contributions', name: 'api_admin_game_contributions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $status = (string) $request->query->get('status', GameTutorialContribution::STATUS_PENDING);
        if (!in_array($status, [GameTutorialContribution::STATUS_PENDING, GameTutorialContribution::STATUS_APPROVED, GameTutorialContribution::STATUS_REJECTED], true)) {
            $status = GameTutorialContribution::STATUS_PENDING;
        }

        return new JsonResponse(['data' => $this->query->list($status)]);
    }

    #[Route('/api/v1/admin/game-contributions/{id}/approve', name: 'api_admin_game_contributions_approve', methods: ['POST'])]
    public function approve(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $overrideSteps = is_array($payload['steps'] ?? null) ? $payload['steps'] : null;

        return $this->respond($this->moderate->approve($id, $admin->getId(), $overrideSteps), 'Contribution appliquée.');
    }

    #[Route('/api/v1/admin/game-contributions/{id}/reject', name: 'api_admin_game_contributions_reject', methods: ['POST'])]
    public function reject(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : '';

        return $this->respond($this->moderate->reject($id, $admin->getId(), $reason), 'Contribution refusée.');
    }

    /**
     * @param array{found: bool, conflict?: bool, errors: array<string, list<string>>} $result
     */
    private function respond(array $result, string $message): JsonResponse
    {
        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Contribution introuvable.', 404);
        }
        if (true === ($result['conflict'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('already_moderated', 'Cette contribution a déjà été modérée.', 409);
        }
        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La modération a échoué.', 422, $result['errors']);
        }

        return new JsonResponse(['meta' => ['message' => $message]]);
    }
}
