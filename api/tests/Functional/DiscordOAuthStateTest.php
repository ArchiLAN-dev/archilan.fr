<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\Port\DiscordOAuthClientInterface;
use App\Identity\Domain\Entity\User;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Story 50.3: the Discord round trip only completes in the browser that started it, and only once. Also the access
 * rules of the liaison: the account linked is the one that opened the flow, never one named by the callback.
 */
final class DiscordOAuthStateTest extends FunctionalTestCase
{
    private const string COOKIE = '__Secure-archilan_discord_state';

    protected function setUp(): void
    {
        parent::setUp();

        $this->client->disableReboot();
        $this->client->setServerParameter('HTTPS', 'on');
        self::getContainer()->set(DiscordOAuthClientInterface::class, new class implements DiscordOAuthClientInterface {
            public function buildAuthorizationUrl(string $redirectUri, string $state): string
            {
                return 'https://discord.example/authorize?state='.$state;
            }

            public function exchangeCode(string $code, string $redirectUri): array
            {
                return ['access_token' => 'access-token'];
            }

            public function fetchUser(string $accessToken): array
            {
                return ['id' => 'discord-42', 'username' => 'Alice', 'email' => 'discord-alice@example.org', 'verified' => true, 'avatar' => null];
            }
        });
    }

    public function testTheSignInCookieIsHttpOnlyLaxAndScopedToTheCallback(): void
    {
        $this->client->request('GET', '/api/v1/auth/discord');

        self::assertResponseStatusCodeSame(302);
        $cookie = $this->stateCookie();
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/api/v1/auth/discord/callback', $cookie->getPath());
    }

    public function testTheBrowserThatStartedTheSignInCompletesIt(): void
    {
        $state = $this->startSignIn();

        $this->client->request('GET', '/api/v1/auth/discord/callback', ['state' => $state, 'code' => 'code']);

        self::assertResponseRedirects();
        self::assertStringEndsWith('/compte', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testASignInStateFromAnotherBrowserIsRefused(): void
    {
        $state = $this->startSignIn();
        $this->client->getCookieJar()->clear();

        $this->client->request('GET', '/api/v1/auth/discord/callback', ['state' => $state, 'code' => 'code']);

        self::assertStringEndsWith('discord_error=access_denied', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testASignInStateWithTheNonceOfAnotherFlowIsRefused(): void
    {
        $state = $this->startSignIn();
        $this->startSignIn();

        $this->client->request('GET', '/api/v1/auth/discord/callback', ['state' => $state, 'code' => 'code']);

        self::assertStringEndsWith('discord_error=access_denied', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testASignInStateServesOnlyOnce(): void
    {
        $state = $this->startSignIn();

        $this->client->request('GET', '/api/v1/auth/discord/callback', ['state' => $state, 'code' => 'code']);
        $this->client->request('GET', '/api/v1/auth/discord/callback', ['state' => $state, 'code' => 'code']);

        self::assertStringEndsWith('discord_error=access_denied', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testStartingTheLiaisonRequiresAnAccount(): void
    {
        $this->client->request('GET', '/api/v1/account/discord/link');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheLiaisonLinksTheAccountThatOpenedIt(): void
    {
        $alice = $this->createUser('alice@example.org');
        $state = $this->startLink($alice);

        $this->client->request('GET', '/api/v1/account/discord/link/callback', ['state' => $state, 'code' => 'code']);

        self::assertStringEndsWith('discord_linked=1', (string) $this->client->getResponse()->headers->get('Location'));
        $this->entityManager->clear();
        self::assertSame('discord-42', $this->reload($alice)->getDiscordId());
    }

    public function testALiaisonStateFromAnotherBrowserLinksNothing(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');
        $state = $this->startLink($alice);

        // Bob's browser follows the link Alice prepared: signed in, but without the nonce of her flow.
        $this->client->getCookieJar()->clear();
        $this->loginAs($bob);
        $this->client->request('GET', '/api/v1/account/discord/link/callback', ['state' => $state, 'code' => 'code']);

        self::assertStringEndsWith('discord_link_error=access_denied', (string) $this->client->getResponse()->headers->get('Location'));
        $this->entityManager->clear();
        self::assertNull($this->reload($alice)->getDiscordId());
        self::assertNull($this->reload($bob)->getDiscordId());
    }

    public function testASignInStateCannotCompleteALiaison(): void
    {
        $alice = $this->createUser('alice@example.org');
        $state = $this->startSignIn();
        $this->startLink($alice);

        $this->client->request('GET', '/api/v1/account/discord/link/callback', ['state' => $state, 'code' => 'code']);

        self::assertStringEndsWith('discord_link_error=access_denied', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function startSignIn(): string
    {
        $this->client->request('GET', '/api/v1/auth/discord');

        return $this->stateOfTheRedirect();
    }

    private function startLink(User $user): string
    {
        $this->loginAs($user);
        $this->client->request('GET', '/api/v1/account/discord/link');

        return $this->stateOfTheRedirect();
    }

    private function stateOfTheRedirect(): string
    {
        self::assertResponseStatusCodeSame(302);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);

        return $query['state'];
    }

    private function stateCookie(): Cookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if (self::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail('The state cookie was not set.');
    }

    private function reload(User $user): User
    {
        $reloaded = $this->entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
