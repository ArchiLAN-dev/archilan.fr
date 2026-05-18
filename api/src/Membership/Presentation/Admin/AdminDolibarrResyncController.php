<?php

declare(strict_types=1);

namespace App\Membership\Presentation\Admin;

use App\Membership\Application\AdminDolibarrResyncService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminDolibarrResyncController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminDolibarrResyncService $resyncService,
    ) {
    }

    #[Route('/api/v1/admin/memberships/dolibarr/resync', name: 'api_membership_admin_dolibarr_resync', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $queued = $this->resyncService->dispatchAll();

        return new JsonResponse(['data' => ['queued' => $queued]], 202);
    }
}
