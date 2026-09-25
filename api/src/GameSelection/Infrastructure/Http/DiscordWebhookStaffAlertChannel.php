<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Http;

use App\GameSelection\Application\Exception\StaffAlertDeliveryException;
use App\GameSelection\Application\Port\StaffAlertChannelInterface;
use App\GameSelection\Application\Support\StaffAlert;
use App\GameSelection\Application\Support\StaffAlertLevel;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts staff alerts to a Discord incoming webhook (story 38.2): a plain JSON POST on a secret URL,
 * no bot involved.
 *
 * Two things this adapter guarantees:
 * - no mention is ever resolved (`allowed_mentions.parse = []`): the text carries error messages from
 *   third-party apworld code, and an `@everyone` in there must stay text;
 * - the webhook URL, which is the credential, never leaks into an exception message or a log.
 */
final readonly class DiscordWebhookStaffAlertChannel implements StaffAlertChannelInterface
{
    public const int COLOR_ALERT = 0xE74C3C;
    public const int COLOR_INFO = 0x3498DB;
    public const int COLOR_RESOLVED = 0x2ECC71;

    private const string USERNAME = 'ArchiLAN - santé des apworlds';
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $webhookUrl,
    ) {
    }

    public function post(StaffAlert $alert): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->webhookUrl, [
                'json' => [
                    'username' => self::USERNAME,
                    'allowed_mentions' => ['parse' => []],
                    'embeds' => [[
                        'title' => $alert->title,
                        'description' => $alert->description,
                        'url' => $alert->url,
                        'color' => self::color($alert->level),
                    ]],
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new StaffAlertDeliveryException(sprintf('Discord webhook unreachable (%s).', $e::class), 0, null);
        }

        if ($status < 200 || $status >= 300) {
            throw new StaffAlertDeliveryException(sprintf('Discord webhook answered HTTP %d.', $status));
        }
    }

    private static function color(StaffAlertLevel $level): int
    {
        return match ($level) {
            StaffAlertLevel::Alert => self::COLOR_ALERT,
            StaffAlertLevel::Info => self::COLOR_INFO,
            StaffAlertLevel::Resolved => self::COLOR_RESOLVED,
        };
    }
}
