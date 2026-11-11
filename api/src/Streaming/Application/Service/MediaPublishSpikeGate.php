<?php

declare(strict_types=1);

namespace App\Streaming\Application\Service;

use Psr\Log\LoggerInterface;

/**
 * Story 47.4 (spike): answers the LAN MediaMTX server asking whether a stream may be published. One fixed test key for
 * every `p-...` path, set by MEDIA_AUTH_SPIKE_KEY; empty = the endpoint does not exist. Story 47.5 replaces it with a
 * key per registration.
 *
 * MediaMTX 1.21 sends the key in `token` for an OBS WHIP "Bearer Token" without a colon, in `password` for SRT
 * (`streamid=publish:<path>:<user>:<key>`) or a `user:key` Bearer. Every request is logged without its secrets: that
 * log is what the spike reads.
 */
final readonly class MediaPublishSpikeGate
{
    private const string PATH_PATTERN = '/^p-[a-z0-9-]{1,40}$/';

    public function __construct(
        private string $spikeKey,
        private LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->spikeKey;
    }

    /**
     * @param array<mixed> $request the JSON body MediaMTX posts (ip, user, password, token, action, path, protocol...)
     */
    public function allows(array $request): bool
    {
        $action = self::text($request, 'action');
        $path = self::text($request, 'path');
        $token = self::text($request, 'token');
        $password = self::text($request, 'password');

        $keyMatches = ('' !== $token && hash_equals($this->spikeKey, $token))
            || ('' !== $password && hash_equals($this->spikeKey, $password));
        $allowed = $this->isEnabled()
            && 'publish' === $action
            && 1 === preg_match(self::PATH_PATTERN, $path)
            && $keyMatches;

        $this->logger->info('streaming.media_auth_spike', [
            'allowed' => $allowed,
            'action' => $action,
            'path' => $path,
            'protocol' => self::text($request, 'protocol'),
            'user' => self::text($request, 'user'),
            'hasToken' => '' !== $token,
            'hasPassword' => '' !== $password,
            'ip' => self::text($request, 'ip'),
            'userAgent' => self::text($request, 'userAgent'),
        ]);

        return $allowed;
    }

    /** @param array<mixed> $request */
    private static function text(array $request, string $key): string
    {
        $value = $request[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
