<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\PasswordResetMessage;
use App\Identity\Application\RegisterLambdaUser;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\PasswordResetToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class PasswordResetTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(RefreshToken::class),
            $this->entityManager->getClassMetadata(PasswordResetToken::class),
            $this->entityManager->getClassMetadata(EmailConfirmationToken::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema(array_reverse($metadata));
        $schemaTool->createSchema($metadata);

        $register = self::getContainer()->get(RegisterLambdaUser::class);
        self::assertInstanceOf(RegisterLambdaUser::class, $register);
        $register->register('user@example.org', 'correct horse battery staple', true);

        // Reset transport — setUp dispatched an EmailConfirmationMessage; each test starts clean.
        $this->asyncTransport()->reset();
    }

    public function testRequestAlwaysReturns204ForValidEmail(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'user@example.org']);

        self::assertResponseStatusCodeSame(204);
    }

    public function testRequestDispatchesMessageForKnownEmail(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'user@example.org']);

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(PasswordResetMessage::class, $sent[0]->getMessage());
    }

    public function testRequestReturns204AndDispatchesNothingForUnknownEmail(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'nobody@example.org']);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    public function testConfirmUpdatesPasswordAndAllowsLogin(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'user@example.org']);
        $rawToken = $this->extractRawToken();

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', [
            'token' => $rawToken,
            'password' => 'new secure password 42',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'user@example.org',
            'password' => 'new secure password 42',
        ]);
        self::assertResponseIsSuccessful();
    }

    public function testOldPasswordNoLongerWorksAfterReset(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'user@example.org']);
        $rawToken = $this->extractRawToken();

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', [
            'token' => $rawToken,
            'password' => 'new secure password 42',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'user@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testConfirmWithInvalidTokenReturns400(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', [
            'token' => 'not-a-real-token',
            'password' => 'new secure password 42',
        ]);

        self::assertResponseStatusCodeSame(400);
        $body = $this->decodedJsonResponse();
        self::assertIsArray($body['error']);
        self::assertSame('invalid_reset_token', $body['error']['code']);
    }

    public function testConfirmTokenCannotBeUsedTwice(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'user@example.org']);
        $rawToken = $this->extractRawToken();

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', [
            'token' => $rawToken,
            'password' => 'first new password',
        ]);
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', [
            'token' => $rawToken,
            'password' => 'second new password',
        ]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testConfirmRevokesAllRefreshTokens(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'user@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/request', ['email' => 'user@example.org']);
        $rawToken = $this->extractRawToken();

        $this->client->jsonRequest('POST', '/api/v1/auth/password-reset/confirm', [
            'token' => $rawToken,
            'password' => 'new secure password 42',
        ]);
        self::assertResponseStatusCodeSame(204);

        $raw = $this->entityManager->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM refresh_token WHERE revoked_at IS NOT NULL');
        self::assertIsInt($raw);
        self::assertGreaterThan(0, $raw);
    }

    private function extractRawToken(): string
    {
        $sent = $this->asyncTransport()->getSent();
        $message = $sent[array_key_last($sent)]->getMessage();
        self::assertInstanceOf(PasswordResetMessage::class, $message);

        return $message->rawToken;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
