<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CannotKudosOwnContentException;
use App\Community\Application\KudosService;
use App\Community\Domain\Kudos;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityKudosController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private KudosService $kudos,
    ) {
    }

    #[Route('/api/v1/community/kudos', name: 'api_community_kudos_toggle', methods: ['POST'])]
    public function toggle(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $type = is_string($payload['targetType'] ?? null) ? $payload['targetType'] : '';
        $id = is_string($payload['targetId'] ?? null) ? trim($payload['targetId']) : '';
        if ('' === $id || !Kudos::isValidTargetType($type)) {
            return $this->apiAccessGuard->errorResponse('invalid', 'Cible de kudos invalide.', 422);
        }

        try {
            return new JsonResponse(['data' => $this->kudos->toggle($user->getId(), $type, $id)]);
        } catch (CannotKudosOwnContentException) {
            return $this->apiAccessGuard->errorResponse('self_kudos', 'On ne peut pas s\'envoyer de kudos.', 422);
        }
    }

    #[Route('/api/v1/community/kudos/state', name: 'api_community_kudos_state', methods: ['POST'])]
    public function state(Request $request): JsonResponse
    {
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $viewerId = $viewer instanceof User ? $viewer->getId() : null;

        $rawTargets = $this->jsonPayload($request)['targets'] ?? null;
        $targets = [];
        if (is_array($rawTargets)) {
            foreach ($rawTargets as $target) {
                if (is_array($target) && is_string($target['targetType'] ?? null) && is_string($target['targetId'] ?? null)) {
                    $targets[] = ['targetType' => $target['targetType'], 'targetId' => $target['targetId']];
                }
            }
        }

        return new JsonResponse(['data' => $this->kudos->state($viewerId, $targets)]);
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
