<?php

declare(strict_types=1);

namespace App\Membership\Presentation;

use App\Membership\Application\AccountMembershipQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountMembershipController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AccountMembershipQuery $membershipQuery,
    ) {
    }

    #[Route('/api/v1/account/membership', name: 'api_membership_account_status', methods: ['GET'])]
    public function status(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse([
            'data' => $this->membershipQuery->queryForUser($user->getId()),
            'meta' => [],
        ]);
    }

    #[Route('/api/v1/account/memberships', name: 'api_membership_account_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse([
            'data' => $this->membershipQuery->queryHistoryForUser($user->getId()),
        ]);
    }
}
