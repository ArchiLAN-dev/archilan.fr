<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\RegisterUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RegisterUserController
{
    public function __construct(private RegisterUser $registerUser)
    {
    }

    #[Route('/api/v1/accounts/register', name: 'api_identity_register_user', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->errorResponse('invalid_json', 'Le corps de la requête doit être un JSON valide.', [], 400);
        }

        if (!is_array($payload)) {
            return $this->errorResponse('invalid_json', 'Le corps de la requête doit être un objet JSON.', [], 400);
        }

        $displayName = is_string($payload['displayName'] ?? null) ? trim($payload['displayName']) : null;

        $result = $this->registerUser->register(
            is_string($payload['email'] ?? null) ? $payload['email'] : '',
            is_string($payload['password'] ?? null) ? $payload['password'] : '',
            ($payload['acceptedCgu'] ?? null) === true,
            '' !== (string) $displayName ? $displayName : null,
        );

        if ([] !== $result['errors']) {
            return $this->errorResponse(
                'validation_failed',
                'Le formulaire contient des erreurs.',
                $result['errors'],
                422,
            );
        }

        if (!isset($result['user'])) {
            throw new \LogicException('Registration succeeded without a user.');
        }

        $user = $result['user'];

        return new JsonResponse([
            'data' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ],
            'meta' => [
                'message' => 'Compte créé.',
            ],
        ], 201);
    }

    /**
     * @param array<string, list<string>> $details
     */
    private function errorResponse(string $code, string $message, array $details, int $status): JsonResponse
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
