<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityProfileView;
use App\Community\Application\UpdateCommunityProfile;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityProfileController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityProfileView $profileView,
        private UpdateCommunityProfile $updateProfile,
    ) {
    }

    #[Route('/api/v1/community/profile', name: 'api_community_profile_me_get', methods: ['GET'])]
    public function myProfile(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => [
            'slug' => $user->getSlug(),
            'accountName' => $user->getDisplayName(),
            ...$this->profileView->editableForUser($user->getId()),
        ]]);
    }

    #[Route('/api/v1/community/profile', name: 'api_community_profile_me_update', methods: ['PUT'])]
    public function updateMyProfile(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->updateProfile->update($user->getId(), $this->jsonPayload($request));
        if (null !== $result['errorCode']) {
            return $this->apiAccessGuard->errorResponse($result['errorCode'], 'Profil invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => [
            'slug' => $user->getSlug(),
            'accountName' => $user->getDisplayName(),
            ...$this->profileView->editableForUser($user->getId()),
        ]]);
    }

    #[Route('/api/v1/community/profiles/{slug}', name: 'api_community_profile', methods: ['GET'])]
    public function profile(Request $request, string $slug): JsonResponse
    {
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $viewerId = $viewer instanceof User ? $viewer->getId() : null;

        $profile = $this->profileView->forSlug($slug, $viewerId);
        if (null === $profile) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(['data' => $profile]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
