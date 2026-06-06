<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\EmailConfirmationMessage;
use App\Identity\Application\RegisterUser;
use App\Identity\Domain\User;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class EmailConfirmationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testRegisterDispatchesConfirmationMessage(): void
    {
        $register = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $register);
        $register->register('newuser@example.org', 'correct horse battery staple', true, 'Jean');

        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(EmailConfirmationMessage::class, $sent[0]->getMessage());
    }

    public function testConfirmEmailReturns204ForValidToken(): void
    {
        $rawToken = $this->registerAndExtractToken();

        $this->client->request('GET', '/api/v1/auth/confirm-email?token='.urlencode($rawToken));

        self::assertResponseStatusCodeSame(204);
    }

    public function testConfirmEmailSetsEmailVerifiedAt(): void
    {
        $rawToken = $this->registerAndExtractToken();

        $this->client->request('GET', '/api/v1/auth/confirm-email?token='.urlencode($rawToken));

        self::assertResponseStatusCodeSame(204);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'newuser@example.org']);
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isEmailVerified());
    }

    public function testConfirmEmailReturns400ForInvalidToken(): void
    {
        $this->client->request('GET', '/api/v1/auth/confirm-email?token=not-a-real-token');

        self::assertResponseStatusCodeSame(400);
        $body = $this->decodedJsonResponse();
        self::assertIsArray($body['error']);
        self::assertSame('invalid_confirmation_token', $body['error']['code']);
    }

    public function testConfirmEmailReturns400ForMissingToken(): void
    {
        $this->client->request('GET', '/api/v1/auth/confirm-email');

        self::assertResponseStatusCodeSame(400);
    }

    public function testConfirmTokenCannotBeUsedTwice(): void
    {
        $rawToken = $this->registerAndExtractToken();

        $this->client->request('GET', '/api/v1/auth/confirm-email?token='.urlencode($rawToken));
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/auth/confirm-email?token='.urlencode($rawToken));
        self::assertResponseStatusCodeSame(400);
    }

    public function testMePayloadIncludesEmailVerifiedAt(): void
    {
        $rawToken = $this->registerAndExtractToken();
        $this->client->request('GET', '/api/v1/auth/confirm-email?token='.urlencode($rawToken));

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'newuser@example.org']);
        self::assertInstanceOf(User::class, $user);
        $this->loginAs($user);

        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertIsArray($body['data']);
        self::assertArrayHasKey('emailVerifiedAt', $body['data']);
        self::assertNotNull($body['data']['emailVerifiedAt']);
    }

    public function testMePayloadHasNullEmailVerifiedAtForUnconfirmedUser(): void
    {
        $register = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $register);
        $result = $register->register('unconfirmed@example.org', 'correct horse battery staple', true, 'Jean');
        self::assertInstanceOf(User::class, $result['user'] ?? null);

        $user = $result['user'];
        $this->loginAs($user);

        $this->client->request('GET', '/api/v1/auth/me');
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertIsArray($body['data']);
        self::assertArrayHasKey('emailVerifiedAt', $body['data']);
        self::assertNull($body['data']['emailVerifiedAt']);
    }

    public function testResendConfirmationRequiresAuth(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/auth/resend-confirmation', []);

        self::assertResponseStatusCodeSame(401);
    }

    public function testResendConfirmationReturns204ForAuthenticatedUser(): void
    {
        $register = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $register);
        $result = $register->register('unconfirmed@example.org', 'correct horse battery staple', true, 'Jean');
        self::assertInstanceOf(User::class, $result['user'] ?? null);

        $user = $result['user'];
        $this->loginAs($user);
        $this->asyncTransport()->reset();

        $this->client->jsonRequest('POST', '/api/v1/auth/resend-confirmation', []);

        self::assertResponseStatusCodeSame(204);
        $sent = $this->asyncTransport()->getSent();
        self::assertCount(1, $sent);
        self::assertInstanceOf(EmailConfirmationMessage::class, $sent[0]->getMessage());
    }

    public function testResendConfirmationSilentForAlreadyVerifiedUser(): void
    {
        $rawToken = $this->registerAndExtractToken();
        $this->client->request('GET', '/api/v1/auth/confirm-email?token='.urlencode($rawToken));

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'newuser@example.org']);
        self::assertInstanceOf(User::class, $user);
        $this->loginAs($user);
        $this->asyncTransport()->reset();

        $this->client->jsonRequest('POST', '/api/v1/auth/resend-confirmation', []);

        self::assertResponseStatusCodeSame(204);
        self::assertCount(0, $this->asyncTransport()->getSent());
    }

    private function registerAndExtractToken(): string
    {
        $register = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $register);
        $register->register('newuser@example.org', 'correct horse battery staple', true, 'Jean');

        $sent = $this->asyncTransport()->getSent();
        $message = $sent[array_key_last($sent)]->getMessage();
        self::assertInstanceOf(EmailConfirmationMessage::class, $message);
        $this->asyncTransport()->reset();

        return $message->rawToken;
    }

    private function asyncTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
