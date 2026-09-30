<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\RegisterPushSubscription;
use App\Community\Application\Command\RegisterPushSubscriptionOutcome;
use App\Community\Application\Command\RemovePushSubscription;
use App\Community\Application\Support\WebPushConfig;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Browser pushes, device by device (story 40.2): the site's public key to subscribe with, then the
 * member's own registration and removal of the device they are on.
 */
final readonly class PushSubscriptionController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WebPushConfig $config,
        private RegisterPushSubscription $register,
        private RemovePushSubscription $remove,
    ) {
    }

    #[Route('/api/v1/push/public-key', name: 'api_push_public_key', methods: ['GET'])]
    public function publicKey(): JsonResponse
    {
        return new JsonResponse(['data' => ['publicKey' => $this->config->publicKey()]]);
    }

    #[Route('/api/v1/account/push-subscriptions', name: 'api_account_push_subscription_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return match ($this->register->register($user->getId(), $this->body($request), $request->headers->get('User-Agent'))) {
            RegisterPushSubscriptionOutcome::Registered => new JsonResponse(['data' => ['registered' => true]], 201),
            RegisterPushSubscriptionOutcome::Invalid => $this->apiAccessGuard->errorResponse('invalid_subscription', 'Abonnement du navigateur invalide.', 422),
            RegisterPushSubscriptionOutcome::Unavailable => $this->apiAccessGuard->errorResponse('push_unavailable', 'Les notifications push ne sont pas disponibles.', 503),
        };
    }

    #[Route('/api/v1/account/push-subscriptions', name: 'api_account_push_subscription_remove', methods: ['DELETE'])]
    public function remove(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $endpoint = $this->body($request)['endpoint'] ?? null;
        if (is_string($endpoint) && '' !== $endpoint) {
            $this->remove->remove($user->getId(), $endpoint);
        }

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function body(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
