<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\ValueObject\Audience;
use App\Community\Domain\ValueObject\BannerPreset;
use App\Identity\Domain\Entity\User;

final class PublicProfileSlugsTest extends FunctionalTestCase
{
    private const string URI = '/api/v1/community/public-profile-slugs';

    public function testListsOnlyPublicAudienceProfilesOfLiveAccounts(): void
    {
        $now = new \DateTimeImmutable('2026-07-29T10:00:00+00:00');

        $this->createProfiledUser('pub@example.org', 'alice-pub', Audience::PUBLIC, $now);
        $this->createProfiledUser('mem@example.org', 'bob-members', Audience::MEMBERS, $now);
        $this->createProfiledUser('fri@example.org', 'carol-friends', Audience::FRIENDS, $now);
        $banned = $this->createProfiledUser('ban@example.org', 'dave-banned', Audience::PUBLIC, $now);
        $banned->ban('spam', $now);
        $this->entityManager->flush();

        $this->client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data, 'only the live public-audience profile is listed');
        $entry = $data[0];
        self::assertIsArray($entry);
        self::assertSame('alice-pub', $entry['slug']);
        self::assertIsString($entry['updatedAt']);
        self::assertNotSame('', $entry['updatedAt']);
    }

    public function testEmptyWhenNoPublicProfileExists(): void
    {
        $this->client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data']);
    }

    private function createProfiledUser(string $email, string $slug, string $audience, \DateTimeImmutable $now): User
    {
        $user = $this->createUser($email, displayName: ucfirst($slug), slug: $slug);
        $profile = CommunityProfile::create($user->getId(), $now);
        $profile->customize(null, null, null, null, BannerPreset::DEFAULT, null, [], [], $audience, [], $now);
        $this->entityManager->persist($profile);
        $this->entityManager->flush();

        return $user;
    }
}
