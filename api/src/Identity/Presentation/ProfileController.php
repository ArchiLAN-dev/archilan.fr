<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\UpdateUserProfile;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ProfileController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private UpdateUserProfile $updateUserProfile,
    ) {
    }

    #[Route('/api/v1/account/profile', name: 'api_identity_profile_show', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse([
            'data' => $this->profilePayload($user),
            'meta' => [],
        ]);
    }

    #[Route('/api/v1/account/profile', name: 'api_identity_profile_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->validationResponse(['body' => ['Le corps de la requête doit être un JSON valide.']]);
        }

        if (!is_array($payload)) {
            return $this->validationResponse(['body' => ['Le corps de la requête doit être un objet JSON.']]);
        }

        if (!array_key_exists('displayName', $payload)) {
            return new JsonResponse([
                'data' => $this->profilePayload($user),
                'meta' => [],
            ]);
        }

        $result = $this->updateUserProfile->update($user, $payload['displayName']);

        if ([] !== $result['errors']) {
            return $this->validationResponse($result['errors']);
        }

        if (!isset($result['user'])) {
            throw new \LogicException('Profile update succeeded without a user.');
        }

        return new JsonResponse([
            'data' => $this->profilePayload($result['user']),
            'meta' => [
                'message' => 'Profil mis à jour.',
            ],
        ]);
    }

    /**
     * @return array{id: string, email: string, displayName: string|null, roles: list<string>, createdAt: string, updatedAt: string}
     */
    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'roles' => $user->getRoles(),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, list<string>> $details
     */
    private function validationResponse(array $details): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('validation_failed', 'Le formulaire contient des erreurs.', 422, $details);
    }
}
