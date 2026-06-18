<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\Community\Application\ProfileCommentService;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityCommentController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ProfileCommentService $comments,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    #[Route('/api/v1/community/profiles/{slug}/comments', name: 'api_community_comments_list', methods: ['GET'])]
    public function list(Request $request, string $slug): JsonResponse
    {
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $viewerId = $viewer instanceof User ? $viewer->getId() : null;

        $ownerId = $this->directory->userIdForSlug($slug);
        if (null === $ownerId) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        $result = $this->comments->list($ownerId, $viewerId, $this->limit($request));
        if ('forbidden' === $result['status']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Commentaires non visibles.', 403);
        }

        return new JsonResponse(['data' => $result['comments']]);
    }

    #[Route('/api/v1/community/profiles/{slug}/comments', name: 'api_community_comments_post', methods: ['POST'])]
    public function post(Request $request, string $slug): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $ownerId = $this->directory->userIdForSlug($slug);
        if (null === $ownerId) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        $body = $this->jsonPayload($request)['body'] ?? null;
        $status = $this->comments->post($ownerId, $user->getId(), is_string($body) ? $body : '');

        return match ($status['status']) {
            'ok' => new JsonResponse(null, 201),
            'rate_limited' => $this->apiAccessGuard->errorResponse('rate_limited', 'Trop de commentaires, réessaie plus tard.', 429),
            'blocked', 'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Tu ne peux pas commenter ce profil.', 403),
            default => $this->apiAccessGuard->errorResponse('invalid', 'Commentaire invalide.', 422),
        };
    }

    #[Route('/api/v1/community/comments/{id}', name: 'api_community_comments_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return match ($this->comments->delete($id, $user->getId())) {
            'ok' => new JsonResponse(null, 204),
            'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Action non autorisée.', 403),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Commentaire introuvable.', 404),
        };
    }

    #[Route('/api/v1/community/comments/{id}/report', name: 'api_community_comments_report', methods: ['POST'])]
    public function report(Request $request, string $id): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $reason = $this->jsonPayload($request)['reason'] ?? null;
        $status = $this->comments->report($id, $user->getId(), is_string($reason) ? $reason : '');

        if ('not_found' === $status) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Commentaire introuvable.', 404);
        }

        return new JsonResponse(null, 204);
    }

    private function limit(Request $request): int
    {
        $limit = $request->query->get('limit');

        return is_numeric($limit) ? (int) $limit : 0;
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
