<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 30.43, through the API: the framing of an uploaded avatar and banner image (point aimed at + zoom), saved
 * with the profile, shown on it and on the cards, and put back to the centre when the image changes.
 */
final class CommunityImageFramingTest extends FunctionalTestCase
{
    // 1x1 transparent PNG.
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const array CENTRED = ['x' => 50, 'y' => 50, 'zoom' => 100];

    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testTheFramingStartsCentredAndIsSavedWithTheProfile(): void
    {
        $this->loginAs($this->member('fay@example.org', 'fay'));
        $this->uploadAvatar();
        $this->uploadBanner();

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame(self::CENTRED, $this->data()['avatarFraming']);
        self::assertSame(self::CENTRED, $this->data()['bannerFraming']);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'audience' => 'public',
            'avatarFraming' => ['x' => 30, 'y' => 20, 'zoom' => 150],
            'bannerFraming' => ['x' => 50, 'y' => 80, 'zoom' => 100],
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['x' => 30, 'y' => 20, 'zoom' => 150], $this->data()['avatarFraming']);
        self::assertSame(['x' => 50, 'y' => 80, 'zoom' => 100], $this->data()['bannerFraming']);

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/fay');
        // The avatar shows even to whoever cannot see the customization, so its framing sits beside it.
        self::assertSame(['x' => 30, 'y' => 20, 'zoom' => 150], $this->data()['avatarFraming']);
        $customization = $this->data()['customization'];
        self::assertIsArray($customization);
        self::assertSame(['x' => 50, 'y' => 80, 'zoom' => 100], $customization['bannerFraming']);
    }

    public function testACardCarriesTheFramingOfAnUploadedAvatarOnly(): void
    {
        $this->loginAs($this->member('fox@example.org', 'fox'));
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'public']);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/fox/comments', ['body' => 'Salut']);

        // No uploaded avatar: nothing to frame.
        self::assertNull($this->commentAuthor()['avatarFraming']);

        // An uploaded avatar, still centred: nothing to send either.
        $this->uploadAvatar();
        self::assertNull($this->commentAuthor()['avatarFraming']);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['avatarFraming' => ['x' => 10, 'y' => 90, 'zoom' => 200]]);
        self::assertSame(['x' => 10, 'y' => 90, 'zoom' => 200], $this->commentAuthor()['avatarFraming']);
    }

    public function testAnOmittedFramingIsKept(): void
    {
        $this->loginAs($this->member('fin@example.org', 'fin'));
        $this->uploadAvatar();
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['avatarFraming' => ['x' => 40, 'y' => 60, 'zoom' => 120]]);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerPreset' => 'neon']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['x' => 40, 'y' => 60, 'zoom' => 120], $this->data()['avatarFraming']);
    }

    public function testAnInvalidFramingIsRefused(): void
    {
        $this->loginAs($this->member('fae@example.org', 'fae'));

        foreach ([['x' => 101, 'y' => 50, 'zoom' => 100], ['x' => 50, 'y' => 50], ['x' => 50, 'y' => 50, 'zoom' => 99], 'centre'] as $invalid) {
            $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerFraming' => $invalid]);
            self::assertResponseStatusCodeSame(422);
            $errors = $this->decodedJsonResponse()['error'] ?? null;
            self::assertIsArray($errors);
            self::assertStringContainsString('Cadrage invalide.', (string) json_encode($errors, JSON_UNESCAPED_UNICODE));
        }
    }

    public function testANewImageOrItsRemovalPutsTheFramingBackToTheCentre(): void
    {
        $this->loginAs($this->member('fig@example.org', 'fig'));
        $this->uploadAvatar();
        $this->uploadBanner();
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', [
            'avatarFraming' => ['x' => 30, 'y' => 20, 'zoom' => 150],
            'bannerFraming' => ['x' => 30, 'y' => 20, 'zoom' => 150],
        ]);

        $this->uploadAvatar();
        $this->client->jsonRequest('DELETE', '/api/v1/community/profile/banner');

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame(self::CENTRED, $this->data()['avatarFraming']);
        self::assertSame(self::CENTRED, $this->data()['bannerFraming']);
    }

    /**
     * @return array<mixed>
     */
    private function commentAuthor(): array
    {
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/fox/comments');
        $comments = $this->data();
        self::assertIsArray($comments[0]);
        $author = $comments[0]['author'];
        self::assertIsArray($author);

        return $author;
    }

    private function uploadAvatar(): void
    {
        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->upload()]);
        self::assertResponseStatusCodeSame(200);
    }

    private function uploadBanner(): void
    {
        $this->client->request('POST', '/api/v1/community/profile/banner', [], ['file' => $this->upload()]);
        self::assertResponseStatusCodeSame(200);
    }

    private function member(string $email, string $slug): User
    {
        $user = $this->createUser($email, slug: $slug);
        $now = new \DateTimeImmutable('-1 month');
        $this->entityManager->persist(Membership::create($user->getId(), $now, $now->add(new \DateInterval('P12M')), 'admin', null, null, $now));
        $this->entityManager->flush();

        return $user;
    }

    private function upload(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'img_');
        file_put_contents($tmp, (string) base64_decode(self::PNG_BASE64, true));

        return new UploadedFile($tmp, 'i.png', null, null, true);
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
