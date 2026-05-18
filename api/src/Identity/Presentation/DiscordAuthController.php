<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\AuthSessionSigner;
use App\Identity\Application\DiscordStateToken;
use App\Identity\Application\HandleDiscordAuthCallback;
use App\Identity\Application\RefreshTokenFactory;
use App\Identity\Application\RefreshTokenRepository;
use App\Identity\Infrastructure\DiscordOAuthClientInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscordAuthController
{
    public function __construct(
        private DiscordOAuthClientInterface $discordClient,
        private DiscordStateToken $stateToken,
        private HandleDiscordAuthCallback $handleCallback,
        private AuthSessionSigner $authSessionSigner,
        private RefreshTokenFactory $refreshTokenFactory,
        private RefreshTokenRepository $refreshTokenRepository,
        private string $discordRedirectUriAuth,
        private string $siteUrl,
    ) {
    }

    #[Route('/api/v1/auth/discord', name: 'api_identity_discord_auth', methods: ['GET'])]
    public function redirect(): RedirectResponse
    {
        $state = $this->stateToken->generate('auth');

        return new RedirectResponse($this->discordClient->buildAuthorizationUrl($this->discordRedirectUriAuth, $state));
    }

    #[Route('/api/v1/auth/discord/callback', name: 'api_identity_discord_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->query->getString('state');
        $code = $request->query->getString('code');
        $error = $request->query->getString('error');

        if ('' !== $error || null === $this->stateToken->verify($state, 'auth')) {
            return new RedirectResponse($this->siteUrl.'/connexion?discord_error=access_denied');
        }

        if ('' === $code) {
            return new RedirectResponse($this->siteUrl.'/connexion?discord_error=access_denied');
        }

        $result = $this->handleCallback->handle($code);

        if ('email_conflict' === $result['outcome']) {
            return new RedirectResponse($this->siteUrl.'/connexion?discord_error=email_conflict');
        }

        if ('logged_in' !== $result['outcome'] && 'registered' !== $result['outcome']) {
            return new RedirectResponse($this->siteUrl.'/connexion?discord_error=generic');
        }

        $user = $result['user'];
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $refreshToken] = $this->refreshTokenFactory->issue(
            $user->getId(),
            $now,
            $request->headers->get('User-Agent'),
            true,
        );
        $this->refreshTokenRepository->save($refreshToken);

        $response = new RedirectResponse($this->siteUrl.'/compte');
        $response->headers->setCookie($this->sessionCookie($this->authSessionSigner->sign($user->getId())));
        $response->headers->setCookie($this->refreshCookie($rawToken));

        return $response;
    }

    private function sessionCookie(string $value): Cookie
    {
        return Cookie::create(AuthSessionSigner::COOKIE_NAME)
            ->withValue($value)
            ->withExpires(time() + AuthSessionSigner::ACCESS_TOKEN_TTL)
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withPath('/');
    }

    private function refreshCookie(string $value): Cookie
    {
        return Cookie::create(AuthController::REFRESH_COOKIE_NAME)
            ->withValue($value)
            ->withExpires(time() + RefreshTokenFactory::TOKEN_TTL_LONG_DAYS * 86400)
            ->withHttpOnly(true)
            ->withSecure(true)
            ->withSameSite(Cookie::SAMESITE_LAX)
            ->withPath(AuthController::REFRESH_COOKIE_SCOPE);
    }
}
