<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\AdminCreateAdminAccount;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminCreateAdminAccountController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminCreateAdminAccount $adminCreateAdminAccount,
    ) {
    }

    #[Route('/api/v1/admin/users/admins', name: 'api_identity_admin_user_create_admin', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $creator = $this->requireAuthenticatedAdmin($request);

        if ($creator instanceof JsonResponse) {
            return $creator;
        }

        $payload = $this->jsonPayload($request);
        $result = $this->adminCreateAdminAccount->create(
            $creator,
            is_string($payload['email'] ?? null) ? $payload['email'] : '',
            is_string($payload['password'] ?? null) ? $payload['password'] : '',
            is_string($payload['displayName'] ?? null) ? $payload['displayName'] : '',
        );

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le formulaire contient des erreurs.', 422, $result['errors']);
        }

        $user = $result['user'] ?? null;
        if (null === $user) {
            return $this->apiAccessGuard->errorResponse('admin_creation_failed', 'La création du compte admin a échoué.', 500);
        }

        return new JsonResponse(['data' => $user, 'meta' => ['message' => 'Compte admin créé.']], 201);
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
