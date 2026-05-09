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
