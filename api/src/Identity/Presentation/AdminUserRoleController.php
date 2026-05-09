<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\AdminChangeUserRole;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminUserRoleController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminChangeUserRole $adminChangeUserRole,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}/role', name: 'api_identity_admin_user_role_update', methods: ['PATCH'])]
    public function __invoke(Request $request, string $userId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);
        $result = $this->adminChangeUserRole->change(
            $admin,
            $userId,
            is_string($payload['role'] ?? null) ? $payload['role'] : '',
            true === ($payload['confirmed'] ?? false),
        );

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le changement de rôle est invalide.', 422, $result['errors']);
        }

        $user = $result['user'] ?? null;
        if (null === $user) {
            return $this->apiAccessGuard->errorResponse('role_change_failed', 'Le changement de rôle a échoué.', 500);
        }

        return new JsonResponse(['data' => $user, 'meta' => []]);
    }

    /**
     * @return array<mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
