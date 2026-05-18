<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Shared\Infrastructure\NullMinioStorage;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminEventCoverImageTest extends FunctionalTestCase
{
    private NullMinioStorage $minioStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $minioStorage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $minioStorage);
        $this->minioStorage = $minioStorage;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Event::class),
            $this->entityManager->getClassMetadata(Registration::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testUploadValidJpegStoresInMinioAndReturnsCoverUrl(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/cover-image', $eventId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $expectedKey = sprintf('events/%s/cover.jpg', $eventId);
        self::assertTrue($this->minioStorage->exists('media', $expectedKey), 'Cover image should be stored in MinIO');

        $event = $this->entityManager->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $event);
        $this->entityManager->refresh($event);
        self::assertSame($expectedKey, $event->getCoverImageKey());

        $body = $this->decodedJsonResponse();
        $data = $this->responseData($body);
        self::assertIsString($data['coverImageUrl']);
        self::assertStringContainsString($expectedKey, $data['coverImageUrl']);
        self::assertSame($expectedKey, $data['coverImageKey']);
    }

    public function testUploadInvalidMimeReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_cover_');
        file_put_contents($tmpFile, 'This is not an image');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.txt', 'text/plain', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/cover-image', $eventId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        self::assertSame('image_invalid_type', $this->errorCode($body));
    }

    public function testUploadOversizedFileReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = $this->createTempImage('image/jpeg', 10 * 1024 * 1024 + 100);
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/cover-image', $eventId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        self::assertSame('image_too_large', $this->errorCode($body));
    }

    public function testUpdatingCoverUrlClearsUploadedCoverKey(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/cover-image', $eventId), [], ['file' => $uploadedFile]);
        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/events/%s', $eventId), [
            'title' => 'Test Event',
            'description' => 'Description de test.',
            'startsAt' => '2027-06-01T10:00:00+00:00',
            'endsAt' => '2027-06-01T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 20,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-31T18:00:00+00:00',
            'isPublic' => true,
            'coverImageMode' => 'url',
            'coverImageUrl' => 'https://cdn.example.test/events/cover.webp',
        ]);

        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        $data = $this->responseData($body);
        self::assertSame('https://cdn.example.test/events/cover.webp', $data['coverImageUrl']);
        self::assertNull($data['coverImageKey']);

        $event = $this->entityManager->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $event);
        $this->entityManager->refresh($event);
        self::assertNull($event->getCoverImageKey());
    }

    public function testUploadRequiresAdmin(): void
    {
        $this->client->request('POST', '/api/v1/admin/events/nonexistent/cover-image');
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);
        $this->client->request('POST', '/api/v1/admin/events/nonexistent/cover-image');
        self::assertResponseStatusCodeSame(403);
    }

    private function createEventViaApi(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/events', [
            'title' => 'Test Event',
            'description' => 'Description de test.',
            'startsAt' => '2027-06-01T10:00:00+00:00',
            'endsAt' => '2027-06-01T22:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 20,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-31T18:00:00+00:00',
            'isPublic' => true,
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = $this->decodedJsonResponse();
        $id = $this->responseData($body)['id'];
        self::assertIsString($id);

        return $id;
    }

    private function createTempImage(string $mime, int $extraBytes = 0): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_cover_');
        $magic = match ($mime) {
            'image/jpeg' => "\xff\xd8\xff\xe0",
            'image/png' => "\x89PNG\r\n\x1a\n",
            default => "\x00\x00",
        };
        file_put_contents($tmpFile, $magic.str_repeat("\x00", max(4, $extraBytes)));

        return $tmpFile;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function responseData(array $body): array
    {
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);

        $result = [];
        foreach ($body['data'] as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorCode(array $body): string
    {
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);
        self::assertArrayHasKey('code', $body['error']);
        self::assertIsString($body['error']['code']);

        return $body['error']['code'];
    }
}
