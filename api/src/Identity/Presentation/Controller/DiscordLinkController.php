<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Command\DiscordLinkOutcome;
use App\Identity\Application\Command\LinkDiscordToAccount;
use App\Identity\Application\Command\UnlinkDiscordFromAccount;
use App\Identity\Application\Port\DiscordOAuthClientInterface;
use App\Identity\Application\Support\DiscordStateToken;
use App\Identity\Presentation\Support\DiscordStateCookie;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscordLinkController
{
    use RequiresAuthTrait;

    private const string CALLBACK_PATH = '/api/v1/account/discord/link/callback';

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private DiscordOAuthClientInterface $discordClient,
        private DiscordStateToken $stateToken,
        private LinkDiscordToAccount $linkDiscord,
        private UnlinkDiscordFromAccount $unlinkDiscord,
        private string $discordRedirectUriLink,
        private string $siteUrl,
    ) {
    }

    #[Route('/api/v1/account/discord/link', name: 'api_identity_discord_link', methods: ['GET'])]
    public function link(Request $request): RedirectResponse|JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $nonce = $this->stateToken->newNonce();
        $state = $this->stateToken->generate('link', $nonce, $user->getId());

        $response = new RedirectResponse($this->discordClient->buildAuthorizationUrl($this->discordRedirectUriLink, $state));
        $response->headers->setCookie(DiscordStateCookie::issue($nonce, self::CALLBACK_PATH));

        return $response;
    }

    #[Route(self::CALLBACK_PATH, name: 'api_identity_discord_link_callback', methods: ['GET'])]
    public function linkCallback(Request $request): RedirectResponse
    {
        $response = $this->completeLink($request);
        DiscordStateCookie::clear($response, self::CALLBACK_PATH);

        return $response;
    }

    private function completeLink(Request $request): RedirectResponse
    {
        $state = $request->query->getString('state');
        $code = $request->query->getString('code');
        $error = $request->query->getString('error');

        if ('' !== $error) {
            return new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=access_denied');
        }

        $userId = $this->stateToken->verify($state, 'link', DiscordStateCookie::read($request));
        if (null === $userId || '' === $userId) {
            return new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=access_denied');
        }

        if ('' === $code) {
            return new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=access_denied');
        }

        $result = $this->linkDiscord->link($userId, $code);

        return match ($result) {
            DiscordLinkOutcome::Linked => new RedirectResponse($this->siteUrl.'/compte/securite?discord_linked=1'),
            DiscordLinkOutcome::DiscordAlreadyUsed => new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=already_used'),
            default => new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=generic'),
        };
    }

    #[Route('/api/v1/account/discord', name: 'api_identity_discord_unlink', methods: ['DELETE'])]
    public function unlink(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->unlinkDiscord->unlink($user->getId());

        return new JsonResponse(null, 204);
    }
}
