<?php

declare(strict_types=1);

namespace App\Streaming\Application\Support;

/**
 * Story 47.5: what a registrant needs to publish their screen to the LAN media server (MediaMTX), derived from
 * APP_SECRET and never stored. The path is opaque (neither the nickname nor the registration id) and stable; the key
 * changes with the registration's key version. Both are hexadecimal: MediaMTX splits a Bearer token on ":", so a key
 * must never hold one (spike 47.4).
 */
final readonly class MediaPublishCredentials
{
    public const int WHIP_PORT = 8889;
    public const int SRT_PORT = 8890;
    public const string PATH_PATTERN = '/^p-[0-9a-f]{20}$/';
    public const int AGENT_MAX_CLOCK_SKEW_SECONDS = 120;

    public function __construct(private string $appSecret)
    {
    }

    public function pathFor(string $registrationId): string
    {
        return 'p-'.substr(hash_hmac('sha256', 'archilan-media-path|'.$registrationId, $this->appSecret), 0, 20);
    }

    public function keyFor(string $eventId, string $registrationId, int $version): string
    {
        return substr(hash_hmac('sha256', sprintf('archilan-media-key|%s|%s|%d', $eventId, $registrationId, $version), $this->appSecret), 0, 32);
    }

    /** Story 47.6: the secret the régie agent signs its reports with, one per event. */
    public function agentSecretFor(string $eventId): string
    {
        return hash_hmac('sha256', 'archilan-media-agent|'.$eventId, $this->appSecret);
    }

    /**
     * v0.66.1: the token the régie screen reads the LAN streams with, one per event. The public list of the broadcast
     * gives the LAN addresses only to who holds it, and MediaMTX asks the site for each read (WHEP Bearer token).
     * Hexadecimal, like a key: never a ":".
     */
    public function readTokenFor(string $eventId): string
    {
        return substr(hash_hmac('sha256', 'archilan-media-read|'.$eventId, $this->appSecret), 0, 32);
    }

    public function isReadToken(string $eventId, string $token): bool
    {
        return '' !== $token && hash_equals($this->readTokenFor($eventId), $token);
    }

    /** Story 47.6: a report the agent signed, `{timestamp}.{body}` under the event's secret, sent within 2 minutes. */
    public function verifyAgentRequest(string $eventId, string $timestamp, string $signature, string $body, \DateTimeImmutable $now): bool
    {
        return ctype_digit($timestamp)
            && abs($now->getTimestamp() - (int) $timestamp) <= self::AGENT_MAX_CLOCK_SKEW_SECONDS
            && hash_equals(hash_hmac('sha256', $timestamp.'.'.$body, $this->agentSecretFor($eventId)), strtolower(trim($signature)));
    }

    public static function whipUrl(string $host, string $path): string
    {
        return sprintf('https://%s:%d/%s/whip', $host, self::WHIP_PORT, $path);
    }

    /** Story 47.8: where the régie screen reads a player's stream (WebRTC, LAN only). */
    public static function whepUrl(string $host, string $path): string
    {
        return sprintf('https://%s:%d/%s/whep', $host, self::WHIP_PORT, $path);
    }

    /** The player name MediaMTX wants in the stream id is not read: the path says who publishes. */
    public static function srtUrl(string $host, string $path, string $key): string
    {
        return sprintf('srt://%s:%d?streamid=publish:%s:archilan:%s&pkt_size=1316', $host, self::SRT_PORT, $path, $key);
    }
}
