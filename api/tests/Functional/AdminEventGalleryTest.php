<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Event;
use App\Identity\Domain\User;
use App\Registrations\Domain\Registration;
use App\Shared\Infrastructure\NullMinioStorage;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminEventGalleryTest extends FunctionalTestCase
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

    public function testUploadValidJpegAppendsToGallery(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/gallery', $eventId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        $data = $this->responseData($body);
        self::assertIsArray($data['photoGallery']);
        self::assertCount(1, $data['photoGallery']);
        self::assertIsString($data['photoGallery'][0]);

        $event = $this->entityManager->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $event);
        $this->entityManager->refresh($event);
        self::assertCount(1, $event->getPhotoGallery());
        $item = $event->getPhotoGallery()[0];
        self::assertSame('upload', $item['source']);
        self::assertStringStartsWith(sprintf('events/%s/gallery/', $eventId), $item['key'] ?? '');
        self::assertTrue($this->minioStorage->exists('media', $item['key'] ?? ''));
    }

    public function testUploadInvalidMimeReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_gallery_');
        file_put_contents($tmpFile, 'Not an image');
        $uploadedFile = new UploadedFile($tmpFile, 'photo.txt', 'text/plain', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/gallery', $eventId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        self::assertSame('image_invalid_type', $this->errorCode($body));
    }

    public function testUploadWhenGalleryFullReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        // fill gallery to 12 items
        for ($i = 0; $i < 12; ++$i) {
            $tmpFile = $this->createTempImage('image/jpeg');
            $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);
            $this->client->request('POST', sprintf('/api/v1/admin/events/%s/gallery', $eventId), [], ['file' => $uploadedFile]);
            self::assertResponseIsSuccessful();
            unlink($tmpFile);
        }

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/gallery', $eventId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        self::assertSame('gallery_full', $this->errorCode($body));
    }

    public function testDeleteGalleryItemAtValidIndex(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        // upload two items
        for ($i = 0; $i < 2; ++$i) {
            $tmpFile = $this->createTempImage('image/jpeg');
            $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);
            $this->client->request('POST', sprintf('/api/v1/admin/events/%s/gallery', $eventId), [], ['file' => $uploadedFile]);
            self::assertResponseIsSuccessful();
            unlink($tmpFile);
        }

        $this->client->request('DELETE', sprintf('/api/v1/admin/events/%s/gallery/0', $eventId));
        self::assertResponseStatusCodeSame(204);

        $event = $this->entityManager->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $event);
        $this->entityManager->refresh($event);
        self::assertCount(1, $event->getPhotoGallery());
    }

    public function testUpdateWithResolvedUploadedUrlKeepsUploadSource(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'photo.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', sprintf('/api/v1/admin/events/%s/gallery', $eventId), [], ['file' => $uploadedFile]);
        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $uploadData = $this->responseData($this->decodedJsonResponse());
        self::assertIsArray($uploadData['photoGallery']);
        $resolvedUrl = $uploadData['photoGallery'][0] ?? null;
        self::assertIsString($resolvedUrl);

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
            'coverImageUrl' => null,
            'photoGallery' => [$resolvedUrl, 'https://cdn.example.test/events/photo-2.webp'],
        ]);

        self::assertResponseIsSuccessful();

        $event = $this->entityManager->find(Event::class, $eventId);
        self::assertInstanceOf(Event::class, $event);
        $this->entityManager->refresh($event);

        $gallery = $event->getPhotoGallery();
        self::assertCount(2, $gallery);
        self::assertSame('upload', $gallery[0]['source']);
        self::assertArrayHasKey('key', $gallery[0]);
        self::assertSame('url', $gallery[1]['source']);
        self::assertSame('https://cdn.example.test/events/photo-2.webp', $gallery[1]['url'] ?? null);
    }

    public function testDeleteGalleryItemAtInvalidIndexReturns404(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $eventId = $this->createEventViaApi();

        $this->client->request('DELETE', sprintf('/api/v1/admin/events/%s/gallery/99', $eventId));
        self::assertResponseStatusCodeSame(404);
    }

    public function testUploadRequiresAdmin(): void
    {
        $this->client->request('POST', '/api/v1/admin/events/nonexistent/gallery');
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);
        $this->client->request('POST', '/api/v1/admin/events/nonexistent/gallery');
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

    private function createTempImage(string $mime): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_gallery_');
        $magic = match ($mime) {
            'image/jpeg' => "\xff\xd8\xff\xe0",
            'image/png' => "\x89PNG\r\n\x1a\n",
            default => "\x00\x00",
        };
        file_put_contents($tmpFile, $magic.str_repeat("\x00", 4));

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
