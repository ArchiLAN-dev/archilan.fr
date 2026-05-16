<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RegisterLambdaUserTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(EmailConfirmationToken::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testRegistersLambdaUserWithHashedPasswordAndUserRoleOnly(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts/register', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
            'acceptedCgu' => true,
        ]);

        self::assertResponseStatusCodeSame(201);
        $payload = $this->decodedJsonResponse();
        self::assertIsArray($payload['data']);
        self::assertSame('jean@example.org', $payload['data']['email']);
        self::assertSame(['ROLE_USER'], $payload['data']['roles']);

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['emailCanonical' => 'jean@example.org']);
        self::assertInstanceOf(User::class, $user);
        self::assertInstanceOf(\DateTimeImmutable::class, $user->getCguAcceptedAt());
        self::assertSame(User::CURRENT_CGU_VERSION, $user->getCguAcceptedVersion());
        self::assertNotSame('correct horse battery staple', $user->getPassword());
        $passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $passwordHasher);
        self::assertTrue(
            $passwordHasher->isPasswordValid(
                $user,
                'correct horse battery staple',
            ),
        );
    }

    public function testRejectsDuplicateEmailWithFieldLevelError(): void
    {
        $payload = [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
            'acceptedCgu' => true,
        ];

        $this->client->jsonRequest('POST', '/api/v1/accounts/register', $payload);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/api/v1/accounts/register', $payload);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertSame('validation_failed', $response['error']['code']);
        self::assertArrayHasKey('email', $response['error']['details']);
    }

    public function testRequiresCguAcceptance(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts/register', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
            'acceptedCgu' => false,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('acceptedCgu', $response['error']['details']);
    }

    public function testRejectsInvalidEmailWithFieldLevelError(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts/register', [
            'email' => 'pas-un-email',
            'password' => 'correct horse battery staple',
            'acceptedCgu' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertSame('validation_failed', $response['error']['code']);
        self::assertArrayHasKey('email', $response['error']['details']);
        self::assertArrayNotHasKey('password', $response['error']['details']);
    }

    public function testRejectsShortPasswordWithFieldLevelError(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/accounts/register', [
            'email' => 'jean@example.org',
            'password' => 'court',
            'acceptedCgu' => true,
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertSame('validation_failed', $response['error']['code']);
        self::assertArrayHasKey('password', $response['error']['details']);
        self::assertArrayNotHasKey('email', $response['error']['details']);
    }

    public function testAllowsConfiguredFrontendCorsPreflight(): void
    {
        $this->client->request('OPTIONS', '/api/v1/accounts/register', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            'http://localhost:3000',
            $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'),
        );
        self::assertSame('true', $this->client->getResponse()->headers->get('Access-Control-Allow-Credentials'));
    }

    public function testRejectsUnconfiguredCorsOrigin(): void
    {
        $this->client->request('OPTIONS', '/api/v1/accounts/register', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }
}
