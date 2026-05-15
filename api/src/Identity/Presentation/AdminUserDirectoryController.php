<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\AdminUserDirectory;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminUserDirectoryController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUserDirectory $adminUserDirectory,
    ) {
    }

    #[Route('/api/v1/admin/users', name: 'api_identity_admin_users', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $roleFilter = $request->query->getString('role') ?: null;
        $validRoles = ['all', 'lambda', 'member', 'admin'];
        if (null !== $roleFilter && !in_array($roleFilter, $validRoles, true)) {
            return $this->apiAccessGuard->errorResponse(
                'invalid_parameter',
                sprintf('Valeur de rôle invalide. Valeurs acceptées : %s.', implode(', ', $validRoles)),
                400,
            );
        }

        return new JsonResponse([
            'data' => $this->adminUserDirectory->search(
                $request->query->getString('q'),
                $roleFilter,
            ),
            'meta' => [],
        ]);
    }
}
