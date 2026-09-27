<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\DiscordAuthOutcome;
use App\Identity\Application\Command\HandleDiscordAuthCallback;
use App\Identity\Application\Port\DiscordOAuthClientInterface;
use App\Identity\Application\Support\AuthSessionSigner;
use App\Identity\Application\Support\DiscordStateToken;
use App\Identity\Application\Support\ModerationContactPass;
use App\Identity\Application\Support\RefreshTokenFactory;
use App\Identity\Application\Support\SlugGenerator;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RefreshTokenRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Presentation\Controller\DiscordAuthController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;

/**
 * Story 39.2: signing in with Discord to a banned or suspended account opens no session, but hands out the
 * pass to write to the moderation, like the password login does.
 */
final class DiscordAuthBlockedAccountTest extends TestCase
{
    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-27 10:00:00');
        $this->user = User::register('bad@example.org', 'bad@example.org', 'hash', $this->clock->now(), 'bad', 'Bad');
        $this->user->linkDiscord('discord-1', 'bad', $this->clock->now());
    }

    public function testABlockedAccountIsReportedAsSuch(): void
    {
        $this->user->ban('Récidive', $this->clock->now());

        $result = $this->command()->handle('code');

        self::assertSame(DiscordAuthOutcome::AccessBlocked, $result->outcome);
        self::assertSame($this->user->getId(), $result->userId);
    }

    public function testAnOpenAccountStillLogsIn(): void
    {
        self::assertSame(DiscordAuthOutcome::LoggedIn, $this->command()->handle('code')->outcome);
    }

    public function testTheCallbackOpensNoSessionButHandsOutThePass(): void
    {
        $this->user->suspendUntil(new \DateTimeImmutable('2026-10-10'), 'Comportement', $this->clock->now());
        $refreshTokens = $this->createMock(RefreshTokenRepositoryInterface::class);
        $refreshTokens->expects(self::never())->method('save');
        $state = new DiscordStateToken('secret', $this->clock);
        $pass = new ModerationContactPass('secret', $this->clock);

        $controller = new DiscordAuthController(
            self::createStub(DiscordOAuthClientInterface::class),
            $state,
            $this->command(),
            new AuthSessionSigner('secret', $this->clock),
            new RefreshTokenFactory(),
            $refreshTokens,
            $pass,
            'https://api.archilan.fr/api/v1/auth/discord/callback',
            'https://archilan.fr',
        );
        $response = $controller->callback(new Request(['state' => $state->generate('auth'), 'code' => 'code']));

        self::assertSame('https://archilan.fr/connexion?discord_error=account_blocked', $response->getTargetUrl());
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie;
        }
        self::assertArrayNotHasKey(AuthSessionSigner::COOKIE_NAME, $cookies);
        self::assertArrayHasKey(ModerationContactPass::COOKIE_NAME, $cookies);
        self::assertSame($this->user->getId(), $pass->verify((string) $cookies[ModerationContactPass::COOKIE_NAME]->getValue()));
        self::assertSame(ModerationContactPass::COOKIE_PATH, $cookies[ModerationContactPass::COOKIE_NAME]->getPath());
    }

    private function command(): HandleDiscordAuthCallback
    {
        $discord = self::createStub(DiscordOAuthClientInterface::class);
        $discord->method('exchangeCode')->willReturn(['access_token' => 'token']);
        $discord->method('fetchUser')->willReturn(['id' => 'discord-1', 'username' => 'bad', 'email' => 'bad@example.org', 'verified' => true]);

        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findByDiscordId')->willReturn($this->user);

        return new HandleDiscordAuthCallback($discord, $users, new SlugGenerator($users), new NullLogger(), $this->clock, 'https://api.archilan.fr/api/v1/auth/discord/callback');
    }
}
