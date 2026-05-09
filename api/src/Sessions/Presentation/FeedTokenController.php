<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class FeedTokenController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
        private HubInterface $mercureHub,
    ) {
    }

    #[Route('/api/v1/sessions/{runId}/feed-token', methods: ['GET'])]
    public function token(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->entityManager->find(Session::class, $runId);

        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        if (!$isAdmin) {
            $count = (int) $this->entityManager->createQueryBuilder()
                ->select('COUNT(r.id)')
                ->from(Registration::class, 'r')
                ->where('r.eventId = :eventId AND r.userId = :userId AND r.status = :status AND r.submittedAt IS NOT NULL')
                ->setParameter('eventId', $session->getEventId())
                ->setParameter('userId', $user->getId())
                ->setParameter('status', Registration::STATUS_RESERVED)
                ->getQuery()
                ->getSingleScalarResult();

            if (0 === $count) {
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
