<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminEventPrivateAccessTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCanConfigurePrivateEventPasswordWithoutPlainTextExposure(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(isPublic: false);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertFalse($response['data']['isPublic']);
        self::assertTrue($response['data']['hasPrivateAccessPassword']);
        self::assertArrayNotHasKey('password', $response['data']);
        self::assertArrayNotHasKey('privateAccessPasswordHash', $response['data']);
        self::assertStringNotContainsString('private-access-passphrase', $this->client->getResponse()->getContent() ?: '');
    }

    public function testPasswordCanOnlyBeConfiguredForPrivateEvents(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(isPublic: true);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('visibility', $response['error']['details']);
    }

    public function testPasswordValidationRejectsShortPassword(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(isPublic: false);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'short',
        ]);

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['error']);
        self::assertIsArray($response['error']['details']);
        self::assertArrayHasKey('password', $response['error']['details']);
    }

    public function testStandardCannotConfigurePrivateAccess(): void
    {
        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $event = $this->makeEvent(isPublic: false);
        $this->loginAs($user);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testPublicListMarksPrivateProtectedEventWithoutExposingPassword(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $event = $this->makeEvent(isPublic: false);
        $this->loginAs($admin);
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s/private-access', $event->getId()), [
            'password' => 'private-access-passphrase',
        ]);
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/events');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(1, $response['data']);
        self::assertIsArray($response['data'][0]);
        self::assertFalse($response['data'][0]['isPublic']);
        self::assertTrue($response['data'][0]['hasPrivateAccessPassword']);
        self::assertArrayNotHasKey('password', $response['data'][0]);
        self::assertArrayNotHasKey('privateAccessPasswordHash', $response['data'][0]);
    }

    private function makeEvent(bool $isPublic): Event
    {
        return $this->createEvent(
            'Private Seed 2027',
            new \DateTimeImmutable('2027-05-31T10:00:00+00:00'),
            new \DateTimeImmutable('2027-05-31T22:00:00+00:00'),
            capacity: 24,
            published: true,
            isPublic: $isPublic,
        );
    }
}
