<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Query\AdminUserActivityQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A user's audit timeline (story 36.5) - the five trails the site had been writing without ever
 * reading them.
 */
final readonly class AdminUserActivityController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUserActivityQuery $activity,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}/activity', name: 'api_identity_admin_user_activity', methods: ['GET'])]
    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $entries = $this->activity->forUser($userId, $request->query->getInt('limit'));
        if (null === $entries) {
            return $this->apiAccessGuard->errorResponse('user_not_found', 'Utilisateur introuvable.', 404);
        }

        return new JsonResponse(['data' => $entries]);
    }
}
