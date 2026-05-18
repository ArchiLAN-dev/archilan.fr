<?php

declare(strict_types=1);

namespace App\Payments\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class HelloAssoConfig
{
    public const FORM_TYPE_EVENT = 'evenements';
    public const FORM_TYPE_MEMBERSHIP = 'adhesions';
    public const FORM_TYPE_SHOP = 'boutiques';

    public function __construct(
        #[Autowire('%env(HELLOASSO_CLIENT_ID)%')]
        private string $clientId,
        #[Autowire('%env(HELLOASSO_CLIENT_SECRET)%')]
        private string $clientSecret,
        #[Autowire('%env(HELLOASSO_ORGANIZATION_SLUG)%')]
        private string $organizationSlug,
        #[Autowire('%env(bool:HELLOASSO_SANDBOX)%')]
        private bool $sandbox,
    ) {
    }

    public function getClientId(): string
    {
        if ('' === $this->clientId) {
            throw new \RuntimeException('HELLOASSO_CLIENT_ID is not configured. Set it in your environment or .env.local.');
        }

        return $this->clientId;
    }

    public function getClientSecret(): string
    {
        if ('' === $this->clientSecret) {
            throw new \RuntimeException('HELLOASSO_CLIENT_SECRET is not configured. Set it in your environment or .env.local.');
        }

        return $this->clientSecret;
    }

    public function getOrganizationSlug(): string
    {
        if ('' === $this->organizationSlug) {
            throw new \RuntimeException('HELLOASSO_ORGANIZATION_SLUG is not configured. Set it in your environment or .env.local.');
        }

        return $this->organizationSlug;
    }

    public function assertApiAccessConfigured(): void
    {
        $this->getClientId();
        $this->getClientSecret();
        $this->getOrganizationSlug();
    }

    public function getApiBaseUrl(): string
    {
        return $this->sandbox
            ? 'https://api.helloasso-sandbox.com/v5'
            : 'https://api.helloasso.com/v5';
    }

    public function getOAuthBaseUrl(): string
    {
        return $this->sandbox
            ? 'https://api.helloasso-sandbox.com'
            : 'https://api.helloasso.com';
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    public static function fromApiFormType(string $apiFormType): string
    {
        return match ($apiFormType) {
            'Membership' => self::FORM_TYPE_MEMBERSHIP,
            'Event' => self::FORM_TYPE_EVENT,
            'PaymentForm' => self::FORM_TYPE_SHOP,
            default => mb_strtolower($apiFormType),
        };
    }

    public function buildEmbedUrl(string $formType, string $formSlug, ?string $email = null): string
    {
        $orgSlug = $this->getOrganizationSlug();
        $baseHost = $this->sandbox ? 'www.helloasso-sandbox.com' : 'www.helloasso.com';
        $url = sprintf('https://%s/associations/%s/%s/%s/widget', $baseHost, $orgSlug, $formType, $formSlug);

        if (null !== $email && '' !== $email) {
            $url .= '?initialEmail='.urlencode($email);
        }

        return $url;
    }
}
