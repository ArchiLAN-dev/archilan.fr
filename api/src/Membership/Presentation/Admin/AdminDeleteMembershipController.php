<?php

declare(strict_types=1);

namespace App\Membership\Presentation\Admin;

use App\Membership\Application\AdminDeleteMembership;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminDeleteMembershipController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminDeleteMembership $adminDeleteMembership,
    ) {
    }

    #[Route('/api/v1/admin/memberships/{membershipId}', name: 'api_membership_admin_delete', methods: ['DELETE'])]
    public function __invoke(Request $request, string $membershipId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $deleted = $this->adminDeleteMembership->delete($membershipId);

        if (!$deleted) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Adhésion introuvable.', 404);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
