<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\RegisterUser;
use App\Identity\Domain\EmailConfirmationToken;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class ProfileTest extends FunctionalTestCase
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
        self::assertSame('Jean', $response['data']['displayName']);
        self::assertSame(['ROLE_USER'], $response['data']['roles']);
        self::assertIsString($response['data']['createdAt']);
        self::assertIsString($response['data']['updatedAt']);
    }

    private function createAndLoginUser(): void
    {
        $registerUser = self::getContainer()->get(RegisterUser::class);
        self::assertInstanceOf(RegisterUser::class, $registerUser);
        $result = $registerUser->register('jean@example.org', 'correct horse battery staple', true, 'Jean');
        self::assertSame([], $result['errors']);

        $this->client->jsonRequest('POST', '/api/v1/auth/login', [
            'email' => 'jean@example.org',
            'password' => 'correct horse battery staple',
        ]);
        self::assertResponseIsSuccessful();
    }
}
