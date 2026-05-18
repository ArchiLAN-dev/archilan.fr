<?php

declare(strict_types=1);

namespace App\Membership\Presentation\Admin;

use App\Membership\Application\AdminCreateMembership;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminCreateMembershipController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminCreateMembership $adminCreateMembership,
    ) {
    }

    #[Route('/api/v1/admin/memberships', name: 'api_membership_admin_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête JSON invalide.', 400);
        }

        $userId = isset($body['userId']) && is_string($body['userId']) ? $body['userId'] : null;
        if (null === $userId || '' === $userId) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le champ userId est requis.', 422, [
                'userId' => ['Le champ userId est requis.'],
            ]);
        }

        $startedAt = new \DateTimeImmutable();
        if (isset($body['startedAt']) && is_string($body['startedAt']) && '' !== $body['startedAt']) {
            try {
                $startedAt = new \DateTimeImmutable($body['startedAt']);
            } catch (\Throwable) {
                return $this->apiAccessGuard->errorResponse('validation_error', 'Format de date startedAt invalide.', 422, [
                    'startedAt' => ['Format de date invalide.'],
                ]);
            }
        }

        $expiresAt = $startedAt->add(new \DateInterval('P12M'));
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

        $membership = $this->adminCreateMembership->create($userId, $startedAt, $expiresAt, $adminNote);

        return new JsonResponse(['data' => $membership], 201);
    }
}
