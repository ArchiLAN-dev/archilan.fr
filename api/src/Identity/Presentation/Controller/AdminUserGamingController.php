<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Query\AdminUserGamingQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A member's game side, per person (story 36.4) - notably their personal runs, which had no admin
 * surface at all.
 */
final readonly class AdminUserGamingController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUserGamingQuery $gaming,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}/gaming', name: 'api_identity_admin_user_gaming', methods: ['GET'])]
    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $gaming = $this->gaming->forUser($userId);
        if (null === $gaming) {
            return $this->apiAccessGuard->errorResponse('user_not_found', 'Utilisateur introuvable.', 404);
        }

        return new JsonResponse(['data' => $gaming]);
    }
}
