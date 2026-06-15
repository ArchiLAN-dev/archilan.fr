<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ProfileController
{
    use RequiresAuthTrait;

    public function __construct(private ApiAccessGuard $apiAccessGuard)
    {
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

    /**
     * @return array{id: string, email: string, displayName: string, discordUsername: string|null, steamProfile: string|null, roles: list<string>, emailVerifiedAt: string|null, createdAt: string, updatedAt: string}
     */
    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'discordUsername' => $user->getDiscordUsername(),
            'steamProfile' => $user->getSteamProfile(),
            'roles' => $user->getRoles(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
