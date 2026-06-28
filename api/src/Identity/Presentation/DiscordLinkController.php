<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\DiscordStateToken;
use App\Identity\Application\LinkDiscordToAccount;
use App\Identity\Application\UnlinkDiscordFromAccount;
use App\Identity\Infrastructure\DiscordOAuthClientInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscordLinkController
{
    use RequiresAuthTrait;

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

        $state = $this->stateToken->generate('link', $user->getId());

        return new RedirectResponse($this->discordClient->buildAuthorizationUrl($this->discordRedirectUriLink, $state));
    }

    #[Route('/api/v1/account/discord/link/callback', name: 'api_identity_discord_link_callback', methods: ['GET'])]
    public function linkCallback(Request $request): RedirectResponse
    {
        $state = $request->query->getString('state');
        $code = $request->query->getString('code');
        $error = $request->query->getString('error');

        if ('' !== $error) {
            return new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=access_denied');
        }

        $userId = $this->stateToken->verify($state, 'link');
        if (null === $userId || '' === $userId) {
            return new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=access_denied');
        }

        if ('' === $code) {
            return new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=access_denied');
        }

        $result = $this->linkDiscord->link($userId, $code);

        return match ($result['outcome']) {
            'linked' => new RedirectResponse($this->siteUrl.'/compte/securite?discord_linked=1'),
            'discord_already_used' => new RedirectResponse($this->siteUrl.'/compte/securite?discord_link_error=already_used'),
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
