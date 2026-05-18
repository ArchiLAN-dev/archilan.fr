<?php

declare(strict_types=1);

namespace App\Membership\Presentation\Admin;

use App\Membership\Application\AdminMembershipListQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminMembershipListController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminMembershipListQuery $membershipListQuery,
    ) {
    }

    #[Route('/api/v1/admin/memberships', name: 'api_membership_admin_list', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $page = max(1, $request->query->getInt('page', 1));
        $limit = min(200, max(1, $request->query->getInt('limit', 50)));
        $status = $request->query->getString('status') ?: null;
        $search = $request->query->getString('search') ?: null;
        $userId = $request->query->getString('userId') ?: null;
        $dateFrom = $request->query->getString('dateFrom') ?: null;
        $dateTo = $request->query->getString('dateTo') ?: null;

        if (null !== $status && !in_array($status, ['active', 'expired'], true)) {
            return $this->apiAccessGuard->errorResponse(
                'invalid_parameter',
                'Valeur de statut invalide. Valeurs acceptées : active, expired.',
                400,
            );
        }

        $result = $this->membershipListQuery->search($page, $limit, $status, $search, $userId, $dateFrom, $dateTo);

        return new JsonResponse($result);
    }
}
