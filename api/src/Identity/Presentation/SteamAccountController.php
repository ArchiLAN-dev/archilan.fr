<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\SaveSteamAccount;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SteamAccountController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SaveSteamAccount $saveSteamAccount,
    ) {
    }

    #[Route('/api/v1/account/steam', name: 'api_identity_steam_save', methods: ['PUT'])]
    public function save(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $rawProfile = is_array($payload) ? ($payload['steamProfile'] ?? null) : null;
        $steamProfile = is_string($rawProfile) ? trim($rawProfile) : '';

        if ('' === $steamProfile) {
            return $this->apiAccessGuard->errorResponse('steam_invalid_input', 'Profil Steam requis.', 422);
        }

        $result = $this->saveSteamAccount->save($user->getId(), $steamProfile);

        if ('invalid_input' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('steam_invalid_input', 'Profil Steam non reconnu.', 422);
        }

        return new JsonResponse(['data' => ['steamProfile' => $steamProfile]]);
    }

    #[Route('/api/v1/account/steam', name: 'api_identity_steam_remove', methods: ['DELETE'])]
    public function remove(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->saveSteamAccount->remove($user->getId());

        return new JsonResponse(null, 204);
    }
}
