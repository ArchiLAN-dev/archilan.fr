<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Query\AdminUserParticipationQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A member's memberships and event registrations, per person (story 36.3) - the second was only ever
 * reachable event by event.
 */
final readonly class AdminUserParticipationController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUserParticipationQuery $participation,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}/participation', name: 'api_identity_admin_user_participation', methods: ['GET'])]
    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $participation = $this->participation->forUser($userId);
        if (null === $participation) {
            return $this->apiAccessGuard->errorResponse('user_not_found', 'Utilisateur introuvable.', 404);
        }

        return new JsonResponse(['data' => $participation]);
    }
}
