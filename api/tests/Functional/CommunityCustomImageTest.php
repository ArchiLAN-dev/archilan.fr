<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 30.40, through the API: a banner image for members and admins, a GIF for admins only, animation only
 * as a GIF, and what a profile shows once the account's status changes.
 */
final class CommunityCustomImageTest extends FunctionalTestCase
{
    // 1x1 transparent PNG.
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

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

    public function testAMemberSetsAStillBannerShownOnTheirProfile(): void
    {
        $member = $this->member('mia@example.org', 'mia');
        $this->loginAs($member);

        $this->client->request('POST', '/api/v1/community/profile/banner', [], ['file' => $this->upload($this->png(), 'b.png')]);
        self::assertResponseStatusCodeSame(200);
        self::assertStringContainsString('community/banners/', $this->text($this->data()['bannerImageUrl'] ?? null));

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        $editor = $this->data();
        self::assertTrue($editor['hasCustomBanner']);
        self::assertSame(['image' => true, 'gif' => false], $editor['bannerUpload']);
        self::assertFalse($editor['avatarGifAllowed']);

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mia');
        $customization = $this->data()['customization'];
        self::assertIsArray($customization);
        self::assertStringContainsString('community/banners/', $this->text($customization['bannerImageUrl']));
        self::assertNull($customization['bannerImageStillUrl'], 'a still image has no separate first frame');
    }

    public function testSomeoneNeitherMemberNorAdminHasNoBannerImage(): void
    {
        $this->loginAs($this->createUser('ned@example.org', slug: 'ned'));

        $this->client->request('POST', '/api/v1/community/profile/banner', [], ['file' => $this->upload($this->png(), 'b.png')]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('banner_not_allowed', $this->errorCode());

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame(['image' => false, 'gif' => false], $this->data()['bannerUpload']);
    }

    public function testAGifIsForAdminsOnly(): void
    {
        $this->loginAs($this->member('max@example.org', 'max'));

        $this->client->request('POST', '/api/v1/community/profile/banner', [], ['file' => $this->upload($this->gif(), 'b.gif')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_gif_admin_only', $this->errorCode());

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->upload($this->gif(), 'a.gif')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_gif_admin_only', $this->errorCode());
    }

    public function testAnimationOnlyComesAsAGifEvenForAnAdmin(): void
    {
        $this->loginAs($this->createUser('ada@example.org', ['ROLE_USER', 'ROLE_ADMIN'], slug: 'ada'));
        $animatedWebp = 'RIFF'.pack('V', 22).'WEBPVP8X'.pack('V', 10)."\x02\0\0\0".str_repeat("\0", 6);

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->upload($animatedWebp, 'a.webp')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('image_animation_unsupported', $this->errorCode());
    }

    public function testAnAdminGifKeepsItsFirstFrameForWhenTheyAreDemoted(): void
    {
        $admin = $this->createUser('ava@example.org', ['ROLE_USER', 'ROLE_ADMIN'], slug: 'ava');
        $this->activeMembership($admin);
        $this->loginAs($admin);

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->upload($this->gif(), 'a.gif')]);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('POST', '/api/v1/community/profile/banner', [], ['file' => $this->upload($this->gif(), 'b.gif')]);
        self::assertResponseStatusCodeSame(200);

        $stored = array_keys(new NullMinioStorage()->getStore());
        self::assertCount(4, $stored, 'each GIF is stored with its first frame');

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/ava');
        $profile = $this->data();
        self::assertStringContainsString('.gif', $this->text($profile['avatarUrl']));
        self::assertIsArray($profile['customization']);
        self::assertStringContainsString('.gif', $this->text($profile['customization']['bannerImageUrl']));
        self::assertStringContainsString('.png', $this->text($profile['customization']['bannerImageStillUrl']));

        // Demoted, still a member: both freeze on their first frame.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE "user" SET roles = :roles WHERE id = :id',
            ['roles' => '["ROLE_USER"]', 'id' => $admin->getId()],
        );
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/ava');
        $profile = $this->data();
        self::assertStringContainsString('.png', $this->text($profile['avatarUrl']));
        self::assertIsArray($profile['customization']);
        self::assertStringContainsString('.png', $this->text($profile['customization']['bannerImageUrl']));
    }

    public function testRemovingTheBannerFallsBackToThePreset(): void
    {
        $this->loginAs($this->member('mel@example.org', 'mel'));
        $this->client->request('POST', '/api/v1/community/profile/banner', [], ['file' => $this->upload($this->png(), 'b.png')]);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('DELETE', '/api/v1/community/profile/banner');
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertFalse($this->data()['hasCustomBanner']);
        self::assertNull($this->data()['bannerImageUrl']);
    }

    private function member(string $email, string $slug): User
    {
        $user = $this->createUser($email, slug: $slug);
        $this->activeMembership($user);

        return $user;
    }

    private function activeMembership(User $user): void
    {
        $now = new \DateTimeImmutable('-1 month');
        $this->entityManager->persist(Membership::create($user->getId(), $now, $now->add(new \DateInterval('P12M')), 'admin', null, null, $now));
        $this->entityManager->flush();
    }

    private function png(): string
    {
        return (string) base64_decode(self::PNG_BASE64, true);
    }

    /** A real GIF, encoded by GD, so the first-frame extraction reads a genuine file. */
    private function gif(): string
    {
        $image = imagecreatetruecolor(4, 2);
        self::assertNotFalse($image);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'img_');
        file_put_contents($tmp, $bytes);

        return new UploadedFile($tmp, $name, null, null, true);
    }

    private function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function errorCode(): ?string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
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
