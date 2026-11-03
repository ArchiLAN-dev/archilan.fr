<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Story 50.3. The reboot is disabled here because the test counters live in an in-memory pool that the client
 * would otherwise reset between two requests.
 */
final class AuthRateLimitTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->client->disableReboot();
    }

    public function testLoginIsRefusedAfterTooManyTriesOnOneAccount(): void
    {
        for ($try = 0; $try < 10; ++$try) {
            $this->login('alice@example.org');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('alice@example.org');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHasHeader('Retry-After');
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('too_many_requests', $error['code'] ?? null);
    }

    public function testTheAccountCountIgnoresTheCaseOfTheEmail(): void
    {
        for ($try = 0; $try < 10; ++$try) {
            $this->login('alice@example.org');
        }

        $this->login(' ALICE@example.org');

        self::assertResponseStatusCodeSame(429);
    }

    public function testOneAccountRefusedLeavesTheOthersOpenFromTheSameAddress(): void
    {
        for ($try = 0; $try < 11; ++$try) {
            $this->login('alice@example.org');
        }

        $this->login('bob@example.org');

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheLoginAddressLimitCoversEveryAccount(): void
    {
        for ($try = 0; $try < 100; ++$try) {
            $this->login(sprintf('user%d@example.org', $try));
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('someone-else@example.org');

        self::assertResponseStatusCodeSame(429);
    }

    public function testTheClientBehindTheProxyIsCountedNotTheProxy(): void
    {
        for ($try = 0; $try < 11; ++$try) {
            $this->login('alice@example.org', '9.9.9.9');
        }
        self::assertResponseStatusCodeSame(429);

        $this->login('alice@example.org', '9.9.9.10');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAForgedForwardedAddressDoesNotEscapeTheLimit(): void
    {
        // The proxy appends the address it saw, so what a client sends itself stays on the left of the list.
        for ($try = 0; $try < 10; ++$try) {
            $this->login('alice@example.org', sprintf('8.8.8.%d, 9.9.9.9', $try));
        }

        $this->login('alice@example.org', '8.8.8.200, 9.9.9.9');

        self::assertResponseStatusCodeSame(429);
    }

    public function testPasswordResetRequestsAreLimited(): void
    {
        for ($try = 0; $try < 10; ++$try) {
            $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'alice@example.org']);
            self::assertResponseStatusCodeSame(204);
        }

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'alice@example.org']);

        self::assertResponseStatusCodeSame(429);
    }

    public function testPasswordResetConfirmationsAreLimited(): void
    {
        for ($try = 0; $try < 20; ++$try) {
            $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', ['token' => 'nope', 'password' => 'whatever-long-enough']);
            self::assertResponseStatusCodeSame(400);
        }

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', ['token' => 'nope', 'password' => 'whatever-long-enough']);

        self::assertResponseStatusCodeSame(429);
    }

    public function testRegistrationsAreLimited(): void
    {
        for ($try = 0; $try < 30; ++$try) {
            $this->client->jsonRequest('POST', '/api/v1/accounts/register', ['email' => 'not-an-email']);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->jsonRequest('POST', '/api/v1/accounts/register', ['email' => 'not-an-email']);

        self::assertResponseStatusCodeSame(429);
    }

    public function testConfirmationResendsAreLimited(): void
    {
        for ($try = 0; $try < 10; ++$try) {
            $this->client->jsonRequest('POST', '/api/v1/auth/resend-confirmation');
            self::assertResponseStatusCodeSame(401);
        }

        $this->client->jsonRequest('POST', '/api/v1/auth/resend-confirmation');

        self::assertResponseStatusCodeSame(429);
    }

    private function login(string $email, ?string $forwardedFor = null): void
    {
        $this->client->jsonRequest(
            'POST',
            '/api/v1/auth/login',
            ['email' => $email, 'password' => 'wrong-password'],
            null === $forwardedFor ? [] : ['HTTP_X_FORWARDED_FOR' => $forwardedFor],
        );
    }
}
