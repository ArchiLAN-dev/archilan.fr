<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\Command\RegisterUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Story 50.3: the allowance of the public authentication routes (config/packages/rate_limiter.yaml), kept out of
 * the controllers so a new route on this list needs no change in them. Counted by client address, and for the login
 * by address and account as well. Runs after the router, which names the route.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final readonly class AuthRateLimitListener
{
    public function __construct(
        private RateLimiterFactoryInterface $authLoginIpLimiter,
        private RateLimiterFactoryInterface $authLoginAccountLimiter,
        private RateLimiterFactoryInterface $authPasswordResetRequestLimiter,
        private RateLimiterFactoryInterface $authPasswordResetConfirmLimiter,
        private RateLimiterFactoryInterface $authRegisterLimiter,
        private RateLimiterFactoryInterface $authResendConfirmationLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        foreach ($this->limitersFor($request) as $limiter) {
            $limit = $limiter->consume();
            if (!$limit->isAccepted()) {
                $event->setResponse($this->tooManyRequests($limit));

                return;
            }
        }
    }

    /** @return list<LimiterInterface> */
    private function limitersFor(Request $request): array
    {
        $ip = $request->getClientIp() ?? 'unknown';

        return match ($request->attributes->get('_route')) {
            'api_identity_login' => [
                $this->authLoginIpLimiter->create($ip),
                $this->authLoginAccountLimiter->create($ip.'|'.$this->submittedEmail($request)),
            ],
            'api_identity_password_reset_request' => [$this->authPasswordResetRequestLimiter->create($ip)],
            'api_identity_password_reset_confirm' => [$this->authPasswordResetConfirmLimiter->create($ip)],
            'api_identity_register_user' => [$this->authRegisterLimiter->create($ip)],
            'api_identity_resend_confirmation' => [$this->authResendConfirmationLimiter->create($ip)],
            default => [],
        };
    }

    private function submittedEmail(Request $request): string
    {
        $payload = json_decode($request->getContent(), true);
        $email = is_array($payload) ? ($payload['email'] ?? null) : null;

        return is_string($email) ? RegisterUser::canonicalizeEmail($email) : '';
    }

    private function tooManyRequests(RateLimit $limit): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => 'too_many_requests',
                'message' => 'Trop de tentatives. Réessaie dans quelques minutes.',
                'details' => [],
            ],
        ], 429, ['Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time())]);
    }
}
