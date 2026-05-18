<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Identity\Application\CurrentUserProvider;
use App\Identity\Domain\User;
use App\Membership\Application\ActiveMembershipQueryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final readonly class ApiAccessGuard
{
    public function __construct(
        private CurrentUserProvider $currentUserProvider,
        private ActiveMembershipQueryInterface $activeMembershipQuery,
    ) {
    }

    public function requireUser(Request $request): User|JsonResponse
    {
        $user = $this->currentUserProvider->userFromRequest($request);

        if (!$user instanceof User) {
            return $this->errorResponse('unauthenticated', 'Authentification requise.', 401);
        }

        return $user;
    }

    public function optionalUser(Request $request): ?User
    {
        return $this->currentUserProvider->userFromRequest($request);
    }

    public function requireMember(Request $request): User|JsonResponse
    {
        $user = $this->requireUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (!$this->activeMembershipQuery->hasActiveMembership($user->getId())) {
            return $this->errorResponse('forbidden', 'Acces reserve aux adherents.', 403);
        }

        return $user;
    }

    public function requireAdmin(Request $request): User|JsonResponse
    {
        $user = $this->requireUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return $this->errorResponse('forbidden', 'Accès réservé aux admins.', 403);
        }

        return $user;
    }

    /**
     * @param array<string, list<string>> $details
     */
    public function errorResponse(string $code, string $message, int $status, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
        ], $status);
    }
}
