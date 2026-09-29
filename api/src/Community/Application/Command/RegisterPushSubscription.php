<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Support\WebPushConfig;
use App\Community\Domain\Entity\PushSubscription;
use App\Community\Domain\Repository\PushSubscriptionRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * A member turns browser pushes on for the device they are on (story 40.2). The body is the browser's
 * `PushSubscription.toJSON()`, plus the encoding it supports. Registering an endpoint already known renews
 * it for this member: a browser keeps its endpoint across the accounts that log into it.
 */
final readonly class RegisterPushSubscription
{
    private const int MAX_ENDPOINT_LENGTH = 2048;
    private const int MAX_KEY_LENGTH = 255;
    private const int MAX_USER_AGENT_LENGTH = 255;
    private const array ENCODINGS = ['aes128gcm', 'aesgcm'];

    public function __construct(
        private PushSubscriptionRepositoryInterface $subscriptions,
        private WebPushConfig $config,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<array-key, mixed> $body
     */
    public function register(string $userId, array $body, ?string $userAgent): RegisterPushSubscriptionOutcome
    {
        if (!$this->config->isConfigured()) {
            return RegisterPushSubscriptionOutcome::Unavailable;
        }

        $endpoint = $body['endpoint'] ?? null;
        $keys = $body['keys'] ?? null;
        $p256dh = is_array($keys) ? ($keys['p256dh'] ?? null) : null;
        $auth = is_array($keys) ? ($keys['auth'] ?? null) : null;
        $encoding = $body['contentEncoding'] ?? 'aes128gcm';

        if (!is_string($endpoint) || !self::isPushEndpoint($endpoint)
            || !self::isKey($p256dh) || !self::isKey($auth)
            || !in_array($encoding, self::ENCODINGS, true)) {
            return RegisterPushSubscriptionOutcome::Invalid;
        }

        $agent = null !== $userAgent && '' !== $userAgent ? mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH) : null;

        $existing = $this->subscriptions->findByEndpoint($endpoint);
        if (null !== $existing) {
            $existing->renew($userId, $p256dh, $auth, $encoding, $agent);
        } else {
            $this->subscriptions->add(PushSubscription::register(
                bin2hex(random_bytes(16)),
                $userId,
                $endpoint,
                $p256dh,
                $auth,
                $encoding,
                $agent,
                $this->clock->now(),
            ));
        }
        $this->subscriptions->flush();

        return RegisterPushSubscriptionOutcome::Registered;
    }

    private static function isPushEndpoint(string $endpoint): bool
    {
        return strlen($endpoint) <= self::MAX_ENDPOINT_LENGTH
            && str_starts_with($endpoint, 'https://')
            && false !== filter_var($endpoint, \FILTER_VALIDATE_URL);
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function isKey(mixed $value): bool
    {
        return is_string($value) && '' !== $value && strlen($value) <= self::MAX_KEY_LENGTH
            && 1 === preg_match('/^[A-Za-z0-9_-]+={0,2}$/', $value);
    }
}
