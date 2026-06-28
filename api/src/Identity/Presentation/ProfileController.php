<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\ChangeUserSlug;
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
        private ChangeUserSlug $changeUserSlug,
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

    #[Route('/api/v1/account/slug', name: 'api_identity_slug_update', methods: ['PUT'])]
    public function updateSlug(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !is_string($body['slug'] ?? null)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le slug est requis.', 422);
        }

        $result = $this->changeUserSlug->change($user->getId(), $body['slug']);

        if ('ok' !== $result['outcome']) {
            $code = $result['error'] ?? 'slug_invalid';
            $details = isset($result['nextAllowedAt']) ? ['nextAllowedAt' => [$result['nextAllowedAt']]] : [];

            return $this->apiAccessGuard->errorResponse($code, self::slugErrorMessage($code), 422, $details);
        }

        return new JsonResponse(['data' => ['slug' => $result['slug'] ?? null]]);
    }

    private static function slugErrorMessage(string $code): string
    {
        return match ($code) {
            'slug_taken' => 'Cette URL est déjà utilisée.',
            'slug_reserved' => 'Cette URL a été libérée récemment et reste réservée 30 jours.',
            'slug_reserved_word' => 'Cette URL est réservée.',
            'slug_cooldown' => 'Tu as déjà changé d\'URL récemment (1 changement tous les 30 jours).',
            'slug_unchanged' => 'C\'est déjà ton URL actuelle.',
            default => 'URL invalide : 3 à 30 caractères, minuscules, chiffres et tirets.',
        };
    }

    /**
     * @return array{id: string, email: string, displayName: string, slug: string|null, nextSlugChangeAllowedAt: string|null, discordUsername: string|null, steamProfile: string|null, roles: list<string>, emailVerifiedAt: string|null, createdAt: string, updatedAt: string}
     */
    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'displayName' => $user->getDisplayName(),
            'slug' => $user->getSlug(),
            'nextSlugChangeAllowedAt' => $this->nextSlugChangeAllowedAt($user),
            'discordUsername' => $user->getDiscordUsername(),
            'steamProfile' => $user->getSteamProfile(),
            'roles' => $user->getRoles(),
            'emailVerifiedAt' => $user->getEmailVerifiedAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $user->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** When the user may next move to a NEW slug (null = now); reclaiming the previous slug is always allowed. */
    private function nextSlugChangeAllowedAt(User $user): ?string
    {
        $changedAt = $user->getSlugChangedAt();
        if (null === $changedAt) {
            return null;
        }

        $next = $changedAt->add(new \DateInterval(sprintf('P%dD', ChangeUserSlug::COOLDOWN_DAYS)));

        return $next > new \DateTimeImmutable() ? $next->format(\DateTimeInterface::ATOM) : null;
    }
}
