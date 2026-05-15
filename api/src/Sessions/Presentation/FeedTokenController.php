<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FeedTokenController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private HubInterface $mercureHub,
    ) {
    }

    #[Route('/api/v1/sessions/{runId}/feed-token', methods: ['GET'])]
    public function token(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($runId);

        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        if (!$isAdmin) {
            if (!$this->sessionQuery->hasActiveEventRegistration($user->getId(), $session['eventId'])) {
                return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
            }
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/feed';

        $factory = $this->mercureHub->getFactory();
        if (null === $factory) {
            return $this->apiAccessGuard->errorResponse('service_unavailable', 'Service de token non disponible.', 503);
        }

        $token = $factory->create(
            subscribe: [$topic],
            additionalClaims: ['exp' => $expiresAt],
        );

        return new JsonResponse([
            'data' => [
                'token' => $token,
                'hubUrl' => $this->mercureHub->getPublicUrl(),
                'topic' => $topic,
            ],
        ]);
    }
}
