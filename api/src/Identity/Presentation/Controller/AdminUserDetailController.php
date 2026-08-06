<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Query\AdminUserDetailQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * One user's admin sheet (story 36.1) - the identity panel the epic's other panels hang off.
 */
final readonly class AdminUserDetailController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUserDetailQuery $userDetail,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}', name: 'api_identity_admin_user_detail', methods: ['GET'])]
    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $detail = $this->userDetail->forUserId($userId);
        if (null === $detail) {
            return $this->apiAccessGuard->errorResponse('user_not_found', 'Utilisateur introuvable.', 404);
        }

        return new JsonResponse(['data' => $detail]);
    }
}
