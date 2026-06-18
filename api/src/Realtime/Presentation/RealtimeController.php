<?php

declare(strict_types=1);

namespace App\Realtime\Presentation;

use App\Realtime\Application\RealtimePublisher;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RealtimeController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private HubInterface $hub,
        private Authorization $authorization,
    ) {
    }

    #[Route('/api/v1/realtime/subscribe-token', name: 'api_realtime_subscribe_token', methods: ['GET'])]
    public function subscribeToken(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        /** @var list<string> $rawTopics */
        $rawTopics = array_values((array) $request->query->all('topics'));

        $allowedTopics = array_values(array_filter(
            $rawTopics,
            static fn (string $t): bool => self::isAdminTopic($t),
        ));

        if ([] === $allowedTopics) {
            return $this->apiAccessGuard->errorResponse('invalid_topics', 'Aucun topic admin valide demandé.', 422);
        }

        $token = $this->hub->getFactory()?->create($allowedTopics) ?? '';
        $this->authorization->setCookie($request, $allowedTopics);

        return new JsonResponse([
            'data' => ['token' => $token, 'hubUrl' => $this->hub->getPublicUrl()],
            'meta' => [],
        ]);
    }

    #[Route('/api/v1/realtime/notifications-token', name: 'api_realtime_notifications_token', methods: ['GET'])]
    public function notificationsToken(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // A user may only subscribe to their own private notification topic.
        $topic = RealtimePublisher::userNotificationsTopic($user->getId());
        $factory = $this->hub->getFactory();
        if (null === $factory) {
            return $this->apiAccessGuard->errorResponse('service_unavailable', 'Service de token non disponible.', 503);
        }

        $token = $factory->create(
            subscribe: [$topic],
            additionalClaims: ['exp' => new \DateTimeImmutable('+1 hour')],
        );

        return new JsonResponse([
            'data' => ['token' => $token, 'hubUrl' => $this->hub->getPublicUrl(), 'topic' => $topic],
            'meta' => [],
        ]);
    }

    private static function isAdminTopic(string $topic): bool
    {
        return (bool) preg_match('#^https://archilan\.fr/events/[^/]+/registrations$#', $topic);
    }
}
