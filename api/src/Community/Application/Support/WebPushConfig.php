<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The site's VAPID identity for browser pushes (story 40.2): the key pair the push services check every
 * push against, and a contact (`mailto:`) they may use. Without the three, pushes are simply off.
 */
final readonly class WebPushConfig
{
    public function __construct(
        #[Autowire('%env(default:default_empty_string:WEB_PUSH_VAPID_PUBLIC_KEY)%')]
        private string $publicKey,
        #[Autowire('%env(default:default_empty_string:WEB_PUSH_VAPID_PRIVATE_KEY)%')]
        private string $privateKey,
        #[Autowire('%env(default:default_empty_string:WEB_PUSH_SUBJECT)%')]
        private string $subject,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->publicKey && '' !== $this->privateKey && '' !== $this->subject;
    }

    /** Handed to the browser to subscribe; null while pushes are off. */
    public function publicKey(): ?string
    {
        return $this->isConfigured() ? $this->publicKey : null;
    }

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}
     */
    public function vapid(): array
    {
        return ['subject' => $this->subject, 'publicKey' => $this->publicKey, 'privateKey' => $this->privateKey];
    }
}
