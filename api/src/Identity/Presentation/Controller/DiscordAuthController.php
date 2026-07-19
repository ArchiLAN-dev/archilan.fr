<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Command\DiscordAuthOutcome;
use App\Identity\Application\Command\HandleDiscordAuthCallback;
use App\Identity\Application\Port\DiscordOAuthClientInterface;
use App\Identity\Application\Support\AuthSessionSigner;
use App\Identity\Application\Support\DiscordStateToken;
use App\Identity\Application\Support\RefreshTokenFactory;
use App\Identity\Domain\Repository\RefreshTokenRepositoryInterface;
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
        private RefreshTokenRepositoryInterface $refreshTokenRepository,
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

        if (DiscordAuthOutcome::EmailConflict === $result->outcome) {
            return new RedirectResponse($this->siteUrl.'/connexion?discord_error=email_conflict');
        }

        // Only logged_in / registered carry a user id; every other outcome authenticates no one.
        $userId = $result->userId;
        if (null === $userId) {
            return new RedirectResponse($this->siteUrl.'/connexion?discord_error=generic');
        }

        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $refreshToken] = $this->refreshTokenFactory->issue(
            $userId,
            $now,
            $request->headers->get('User-Agent'),
            true,
        );
        $this->refreshTokenRepository->save($refreshToken);

        $response = new RedirectResponse($this->siteUrl.'/compte');
        $response->headers->setCookie($this->sessionCookie($this->authSessionSigner->sign($userId)));
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
