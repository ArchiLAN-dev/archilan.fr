<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class PlayerStateController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private HubInterface $mercureHub,
        private HttpClientInterface $httpClient,
    ) {
    }

    #[Route('/api/v1/sessions/{runId}/players', methods: ['GET'])]
    public function players(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $runId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse(
                'session_not_running',
                sprintf('La session est en état "%s", pas encore en cours.', $session->getStatus()),
                409,
            );
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/state', $host, $bridgePort),
                ['timeout' => 3],
            );
            $data = $response->toArray();

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{runId}/players-token', methods: ['GET'])]
    public function playersToken(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $runId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/players';

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

    #[Route('/api/v1/sessions/{runId}/slots/{slotIndex}/reachable-token', methods: ['GET'])]
    public function reachableToken(Request $request, string $runId, int $slotIndex): JsonResponse
    {
        $user = $this->apiAccessGuard->requireAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $runId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/slots/'.$slotIndex.'/reachable';

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

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/hints', methods: ['GET'])]
    public function slotHints(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/hints/%d', $host, $bridgePort, $slotIndex),
                ['timeout' => 5],
            );

            return new JsonResponse(['data' => $response->toArray()]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{runId}/slots/{slotIndex}/hints-token', methods: ['GET'])]
    public function hintsToken(Request $request, string $runId, int $slotIndex): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $runId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (!$this->isAuthorized($user, $session)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');
        $topic = 'runs/'.$runId.'/slots/'.$slotIndex.'/hints';

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

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/hints/request', methods: ['POST'])]
    public function requestHint(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->apiAccessGuard->requireAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Corps de requête invalide.', 422);
        }
        $locationId = $body['location_id'] ?? null;
        $free = (bool) ($body['free'] ?? false);

        if (!is_int($locationId) || $locationId <= 0) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'location_id (entier > 0) requis.', 422);
        }

        try {
            $response = $this->httpClient->request(
                'POST',
                sprintf('http://%s:%d/hints/%d/request', $host, $bridgePort, $slotIndex),
                [
                    'timeout' => 5,
                    'json' => ['location_id' => $locationId, 'free' => $free],
                ],
            );

            return new JsonResponse(['data' => $response->toArray()]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/item-locations', methods: ['GET'])]
    public function slotItemLocations(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->apiAccessGuard->requireAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/item-locations/%d', $host, $bridgePort, $slotIndex),
                ['timeout' => 5],
            );

            return new JsonResponse(['data' => $response->toArray()]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/reachable', methods: ['GET'])]
    public function slotReachable(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $session = $this->entityManager->find(Session::class, $sessionId);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if (Session::STATUS_RUNNING !== $session->getStatus()) {
            return $this->apiAccessGuard->errorResponse(
                'session_not_running',
                'La session n\'est pas en cours.',
                409,
            );
        }

        $host = $session->getHost();
        $bridgePort = $session->getBridgePort();

        if (null === $host || null === $bridgePort) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('http://%s:%d/reachable/%d', $host, $bridgePort, $slotIndex),
                ['timeout' => 130],
            );
            $data = $response->toArray();

            $user = $this->apiAccessGuard->optionalUser($request);
            $isAdmin = $user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true);

            if (!$isAdmin) {
                $data = $this->stripItemRewards($data);
            }

            return new JsonResponse(['data' => $data]);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    private function stripItemRewards(array $data): array
    {
        $strip = static function (array $locations): array {
            return array_map(static function (mixed $entry): mixed {
                if (is_array($entry)) {
                    unset($entry['item']);
                }

                return $entry;
            }, $locations);
        };

        foreach (['reachable_unchecked', 'reachable_checked', 'unreachable_unchecked', 'checked_unreachable'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = $strip($data[$key]);
            }
        }

        if (isset($data['spheres']) && is_array($data['spheres'])) {
            $data['spheres'] = array_map(static function (mixed $sphere) use ($strip): mixed {
                if (!is_array($sphere)) {
                    return $sphere;
                }
                if (isset($sphere['locations']) && is_array($sphere['locations'])) {
                    $sphere['locations'] = $strip($sphere['locations']);
                }

                return $sphere;
            }, $data['spheres']);
        }

        return $data;
    }

    private function isAuthorized(User $user, Session $session): bool
    {
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Registration::class, 'r')
            ->where('r.eventId = :eventId AND r.userId = :userId AND r.status = :status AND r.submittedAt IS NOT NULL')
            ->setParameter('eventId', $session->getEventId())
            ->setParameter('userId', $user->getId())
            ->setParameter('status', Registration::STATUS_RESERVED)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
