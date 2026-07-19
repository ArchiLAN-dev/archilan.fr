<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Command\AdminCreateAdminAccount;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
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

        // Validation failures are thrown as a ValidationException and mapped to HTTP by
        // ApplicationFailureListener (epic 35).
        return new JsonResponse(['data' => $result, 'meta' => ['message' => 'Compte admin créé.']], 201);
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
