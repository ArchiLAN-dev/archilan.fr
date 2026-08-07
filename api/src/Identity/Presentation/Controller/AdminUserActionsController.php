<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Service\AdminUserActions;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The closed list of admin actions on a member's objects (story 36.6). No impersonation: the admin acts
 * as themselves and every action is attributed to them.
 */
final readonly class AdminUserActionsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUserActions $actions,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}/revoke-sessions', name: 'api_identity_admin_user_revoke_sessions', methods: ['POST'])]
    public function revokeSessions(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->actions->revokeSessions($admin->getId(), $userId));
    }

    #[Route('/api/v1/admin/users/{userId}/verify-email', name: 'api_identity_admin_user_verify_email', methods: ['POST'])]
    public function verifyEmail(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->actions->verifyEmail($admin->getId(), $userId));
    }

    #[Route('/api/v1/admin/users/{userId}/runs/{runId}/stop', name: 'api_identity_admin_user_stop_run', methods: ['POST'])]
    public function stopRun(Request $request, string $userId, string $runId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->actions->stopRun($admin->getId(), $userId, $runId));
    }

    private function respond(string $result): JsonResponse
    {
        return match ($result) {
            'ok' => new JsonResponse(null, 204),
            // Nothing changed - not an error, and deliberately not traced.
            'already' => new JsonResponse(null, 204),
            'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Tu ne peux pas appliquer cette action à ton propre compte.', 403),
            'not_running' => $this->apiAccessGuard->errorResponse('run_not_running', 'Cette run de ce membre n\'a pas de partie en cours.', 422),
            default => $this->apiAccessGuard->errorResponse('user_not_found', 'Utilisateur introuvable.', 404),
        };
    }
}
