<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\RequestPasswordReset;
use App\Identity\Application\ResetPassword;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PasswordResetController
{
    public function __construct(
        private RequestPasswordReset $requestPasswordReset,
        private ResetPassword $resetPassword,
    ) {
    }

    #[Route('/api/v1/auth/password-reset/request', name: 'api_identity_password_reset_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(null, 204);
        }

        if (!is_array($payload) || !is_string($payload['email'] ?? null)) {
            return new JsonResponse(null, 204);
        }

        $this->requestPasswordReset->request($payload['email'], new \DateTimeImmutable());

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/auth/password-reset/confirm', name: 'api_identity_password_reset_confirm', methods: ['POST'])]
    public function confirm(Request $request): JsonResponse
    {
        try {
            $payload = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->tokenError();
        }

        if (!is_array($payload)
            || !is_string($payload['token'] ?? null)
            || !is_string($payload['password'] ?? null)
            || '' === $payload['token']
            || '' === $payload['password']
        ) {
            return $this->tokenError();
        }

        try {
            $this->resetPassword->reset($payload['token'], $payload['password'], new \DateTimeImmutable());
        } catch (\InvalidArgumentException) {
            return $this->tokenError();
        }

        return new JsonResponse(null, 204);
    }

    private function tokenError(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'invalid_reset_token',
                'message' => 'Ce lien de réinitialisation est invalide ou a expiré.',
                'details' => [],
            ],
        ], 400);
    }
}
