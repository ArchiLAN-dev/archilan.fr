<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\ConfirmEmail;
use App\Identity\Application\ResendEmailConfirmation;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class EmailConfirmationController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ConfirmEmail $confirmEmail,
        private ResendEmailConfirmation $resendEmailConfirmation,
    ) {
    }

    #[Route('/api/v1/auth/confirm-email', name: 'api_identity_confirm_email', methods: ['GET'])]
    public function confirm(Request $request): JsonResponse
    {
        $token = $request->query->get('token');

        if (!is_string($token) || '' === $token) {
            return $this->tokenError();
        }

        $result = $this->confirmEmail->confirm($token, new \DateTimeImmutable());

        if ('confirmed' !== $result) {
            return $this->tokenError();
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/auth/resend-confirmation', name: 'api_identity_resend_confirmation', methods: ['POST'])]
    public function resend(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->resendEmailConfirmation->resend($user->getId(), new \DateTimeImmutable());

        return new JsonResponse(null, 204);
    }

    private function tokenError(): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'invalid_confirmation_token',
                'message' => 'Ce lien de confirmation est invalide ou a expiré.',
                'details' => [],
            ],
        ], 400);
    }
}
