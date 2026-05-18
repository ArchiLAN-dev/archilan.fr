<?php

declare(strict_types=1);

namespace App\Membership\Presentation\Admin;

use App\Membership\Application\AdminEditMembership;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminEditMembershipController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminEditMembership $adminEditMembership,
    ) {
    }

    #[Route('/api/v1/admin/memberships/{membershipId}', name: 'api_membership_admin_edit', methods: ['PATCH'])]
    public function __invoke(Request $request, string $membershipId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête JSON invalide.', 400);
        }

        $startedAtRaw = isset($body['startedAt']) && is_string($body['startedAt']) ? $body['startedAt'] : null;
        if (null === $startedAtRaw || '' === $startedAtRaw) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le champ startedAt est requis.', 422, [
                'startedAt' => ['Le champ startedAt est requis.'],
            ]);
        }

        try {
            $startedAt = new \DateTimeImmutable($startedAtRaw);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Format de date startedAt invalide.', 422, [
                'startedAt' => ['Format de date invalide.'],
            ]);
        }

        $expiresAt = null;
        if (isset($body['expiresAt']) && is_string($body['expiresAt']) && '' !== $body['expiresAt']) {
            try {
                $expiresAt = new \DateTimeImmutable($body['expiresAt']);
            } catch (\Throwable) {
                return $this->apiAccessGuard->errorResponse('validation_error', 'Format de date expiresAt invalide.', 422, [
                    'expiresAt' => ['Format de date invalide.'],
                ]);
            }
        }

        $adminNote = isset($body['adminNote']) && is_string($body['adminNote']) ? $body['adminNote'] : null;

        $result = $this->adminEditMembership->edit($membershipId, $startedAt, $expiresAt, $adminNote);

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Adhésion introuvable.', 404);
        }

        return new JsonResponse(['data' => $result]);
    }
}
