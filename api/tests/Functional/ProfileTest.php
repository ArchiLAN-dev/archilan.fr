<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\RegisterLambdaUser;
use App\Identity\Domain\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProfileTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $metadata = [$this->entityManager->getClassMetadata(User::class)];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedProfileAccessIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/account/profile');

        self::assertResponseStatusCodeSame(401);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertSame('unauthenticated', $response['error']['code']);
    }

    public function testAuthenticatedProfileViewReturnsMetadataAndReadOnlyRoles(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('GET', '/api/v1/account/profile');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('jean@example.org', $response['data']['email']);
        self::assertNull($response['data']['displayName']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);
        self::assertIsString($response['data']['createdAt']);
        self::assertIsString($response['data']['updatedAt']);
    }

    public function testAuthenticatedUserCanUpdateDisplayName(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('PATCH', '/api/v1/account/profile', [
            'displayName' => 'Jean Archipelago',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Jean Archipelago', $response['data']['displayName']);
        self::assertSame('jean@example.org', $response['data']['email']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);
    }

    public function testInvalidDisplayNameReturnsFieldLevelError(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('PATCH', '/api/v1/account/profile', [
            'displayName' => str_repeat('a', 81),
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('displayName', $response['error']['details']);
    }

    public function testDisplayNameWhitespaceOnlyIsNormalizedToNull(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('PATCH', '/api/v1/account/profile', [
            'displayName' => '   ',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['displayName']);
    }

    public function testPatchWithoutDisplayNameDoesNotModifyExistingName(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('PATCH', '/api/v1/account/profile', [
            'displayName' => 'Jean Archipelago',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('PATCH', '/api/v1/account/profile', []);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('Jean Archipelago', $response['data']['displayName']);
    }

    public function testProfileUpdateCannotChangeRoles(): void
    {
        $this->createAndLoginUser();

        $this->client->jsonRequest('PATCH', '/api/v1/account/profile', [
            'displayName' => 'Jean',
            'roles' => ['ROLE_ADMIN'],
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);
    }

    private function createAndLoginUser(): void
    {
        $registerLambdaUser = self::getContainer()->get(RegisterLambdaUser::class);
        self::assertInstanceOf(RegisterLambdaUser::class, $registerLambdaUser);
        $result = $registerLambdaUser->register('jean@example.org', 'correct horse battery staple', true);
        self::assertSame([], $result['errors']);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
