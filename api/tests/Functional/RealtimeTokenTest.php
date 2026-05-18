<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class RealtimeTokenTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [$this->entityManager->getClassMetadata(User::class)];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminReceivesTokenForValidAdminTopic(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $this->client->setServerParameter('HTTP_HOST', 'example.com');

        $this->client->jsonRequest(
            'GET',
            '/api/v1/realtime/subscribe-token?topics[]=https://archilan.fr/events/abc123/registrations',
        );

        self::assertResponseStatusCodeSame(200);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertIsArray($response['data']);
        self::assertArrayHasKey('token', $response['data']);
        self::assertIsString($response['data']['token']);
        self::assertNotEmpty($response['data']['token']);
        self::assertArrayHasKey('hubUrl', $response['data']);
        self::assertStringContainsString(
            'mercureAuthorization=',
            (string) $this->client->getResponse()->headers->get('set-cookie'),
        );
    }

    public function testReturns422WhenNoValidTopicsRequested(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/realtime/subscribe-token');

        self::assertResponseStatusCodeSame(422);
    }

    public function testReturns422ForPublicTopics(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest(
            'GET',
            '/api/v1/realtime/subscribe-token?topics[]=https://archilan.fr/events/abc123/seat-counter',
        );

        self::assertResponseStatusCodeSame(422);
    }

    public function testStandardCannotGetSubscribeToken(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);

        $this->client->jsonRequest(
            'GET',
            '/api/v1/realtime/subscribe-token?topics[]=https://archilan.fr/events/abc123/registrations',
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnonymousCannotGetSubscribeToken(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/realtime/subscribe-token');

        self::assertResponseStatusCodeSame(401);
    }
}
