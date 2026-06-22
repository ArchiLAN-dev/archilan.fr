<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementDefinition;
use App\Community\Domain\AchievementGrant;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminAchievementImageTest extends FunctionalTestCase
{
    // 1x1 transparent PNG.
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const string IMAGE_KEY = 'community/achievement-images/abc.png';

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

    public function testAdminUploadReturnsKeyAndPresignedUrl(): void
    {
        $this->loginAs($this->createAdmin());

        $this->client->request('POST', '/api/v1/admin/community/achievements/image', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(200);

        $data = $this->data();
        $key = $data['key'] ?? null;
        self::assertIsString($key);
        self::assertStringStartsWith('community/achievement-images/', $key);
        $url = $data['imageUrl'] ?? null;
        self::assertIsString($url);
        self::assertStringContainsString($key, $url);
    }

    public function testUploadRejectsNonImage(): void
    {
        $this->loginAs($this->createAdmin());

        $tmp = (string) tempnam(sys_get_temp_dir(), 'ach_');
        file_put_contents($tmp, 'this is not an image');
        $file = new UploadedFile($tmp, 'note.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/admin/community/achievements/image', [], ['file' => $file]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRequiresAdmin(): void
    {
        $this->loginAs($this->createUser('plain@example.org', slug: 'plain'));

        $this->client->request('POST', '/api/v1/admin/community/achievements/image', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testCreatePersistsImageKeyAndExposesUrl(): void
    {
        $this->loginAs($this->createAdmin());

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', [
            'key' => 'imaged',
            'name' => 'Imaged',
            'description' => '',
            'rule' => ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1]]],
            'customImageKey' => self::IMAGE_KEY,
        ]);
        self::assertResponseStatusCodeSame(201);

        $data = $this->data();
        self::assertSame(self::IMAGE_KEY, $data['customImageKey']);
        $url = $data['customImageUrl'] ?? null;
        self::assertIsString($url);
        self::assertStringContainsString(self::IMAGE_KEY, $url);
    }

    public function testCustomImageSurfacesOnProfileAndCatalogue(): void
    {
        $user = $this->createUser('alice@example.org', slug: 'alice');
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $rule = ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1]]];
        $this->entityManager->persist(AchievementDefinition::create('imaged', 'Imaged', '', $rule, 1, $now, self::IMAGE_KEY));
        $this->entityManager->persist(AchievementGrant::grant($user->getId(), 'imaged', $now));
        $this->entityManager->flush();

        // Profile recent slice (unlocked) carries the image URL.
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        $profileImaged = $this->achievementByKey($this->data()['achievements'] ?? null, 'imaged');
        self::assertStringContainsString(self::IMAGE_KEY, $this->stringField($profileImaged, 'customImageUrl'));

        // Catalogue carries it too.
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice/achievements');
        self::assertResponseIsSuccessful();
        $catImaged = $this->achievementByKey($this->data()['achievements'] ?? null, 'imaged');
        self::assertStringContainsString(self::IMAGE_KEY, $this->stringField($catImaged, 'customImageUrl'));
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'admin');
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'ach_');
        file_put_contents($tmp, (string) base64_decode(self::PNG_BASE64, true));

        return new UploadedFile($tmp, 'badge.png', 'image/png', null, true);
    }

    /**
     * @return array<mixed>
     */
    private function achievementByKey(mixed $achievements, string $key): array
    {
        self::assertIsArray($achievements);
        foreach ($achievements as $achievement) {
            if (is_array($achievement) && ($achievement['key'] ?? null) === $key) {
                return $achievement;
            }
        }
        self::fail(sprintf('Achievement "%s" not found.', $key));
    }

    /**
     * @param array<mixed> $achievement
     */
    private function stringField(array $achievement, string $field): string
    {
        $value = $achievement[$field] ?? null;
        self::assertIsString($value);

        return $value;
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
