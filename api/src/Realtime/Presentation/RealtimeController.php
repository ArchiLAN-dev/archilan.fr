<?php

declare(strict_types=1);

namespace App\Realtime\Presentation;

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

    private static function isAdminTopic(string $topic): bool
    {
        return (bool) preg_match('#^https://archilan\.fr/events/[^/]+/registrations$#', $topic);
    }
}
