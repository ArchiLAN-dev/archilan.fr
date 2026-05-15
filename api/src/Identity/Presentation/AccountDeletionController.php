<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Application\DeleteAccount;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountDeletionController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private DeleteAccount $deleteAccount,
    ) {
    }

    #[Route('/api/v1/account', name: 'api_identity_account_delete', methods: ['DELETE'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->deleteAccount->delete($user);
        $response = new JsonResponse([
            'data' => [
                'deleted' => true,
            ],
            'meta' => [
                'message' => 'Compte supprimé.',
            ],
        ]);
        $response->headers->clearCookie(
            AuthSessionSigner::COOKIE_NAME,
            '/',
            null,
            true,
            true,
            Cookie::SAMESITE_LAX,
        );

        return $response;
    }
}
