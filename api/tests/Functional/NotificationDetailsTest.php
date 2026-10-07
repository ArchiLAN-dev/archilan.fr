<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\DefaultAchievementDefinitions;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Entity\ProfileTitleDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Enum\TitleIcon;
use App\Community\Domain\Enum\TitleRarity;
use App\Identity\Domain\Entity\User;

/**
 * Story 30.48: what a notification shows besides its payload, resolved when the member reads it.
 */
final class NotificationDetailsTest extends FunctionalTestCase
{
    public function testAnAchievementNotificationCarriesItsNameAndDescription(): void
    {
        $member = $this->createUser('member@example.org', slug: 'member');
        $rule = DefaultAchievementDefinitions::all()[0]['rule'];
        $this->entityManager->persist(AchievementDefinition::create('witch_of_envy', 'Je t\'aime', 'Recevoir 3 000 items.', $rule, 99, new \DateTimeImmutable()));
        $this->notify($member, Notification::TYPE_ACHIEVEMENT_UNLOCKED, ['achievementKey' => 'witch_of_envy']);
        $this->notify($member, Notification::TYPE_ACHIEVEMENT_UNLOCKED, ['achievementKey' => 'gone']);

        $details = $this->detailsOf($member);

        self::assertContains(['achievement' => ['name' => 'Je t\'aime', 'description' => 'Recevoir 3 000 items.', 'imageUrl' => null]], $details);
        self::assertContains(null, $details, 'a deleted achievement keeps the notification, without details');
    }

    public function testACosmeticNotificationCarriesItsPreview(): void
    {
        $member = $this->createUser('member@example.org', slug: 'member');
        $this->entityManager->persist(ProfileTitleDefinition::write('phil', 'Phil Connors', AvatarFrameAccess::Reward, 1, new \DateTimeImmutable(), TitleRarity::Legendary, TitleIcon::Crown));
        $this->notify($member, Notification::TYPE_COSMETIC_UNLOCKED, ['type' => 'title', 'key' => 'phil', 'label' => 'Titre « Phil Connors »', 'source' => 'quest', 'sourceLabel' => 'Un jour sans fin']);

        self::assertSame([['cosmetic' => ['name' => 'Phil Connors', 'rarity' => 'legendary', 'icon' => 'crown']]], $this->detailsOf($member));
    }

    public function testAFriendRequestCanBeAnsweredOnlyWhilePending(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/bob/friend-request');
        self::assertResponseIsSuccessful();

        $details = $this->detailsOf($bob);
        $request = $details[0]['friendRequest'] ?? null;
        self::assertIsArray($request);
        self::assertIsString($request['id'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/community/friendships/'.$request['id'].'/accept');
        self::assertResponseIsSuccessful();
        self::assertSame([null], $this->detailsOf($bob), 'an answered request is no longer offered');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function notify(User $recipient, string $type, array $payload): void
    {
        $this->entityManager->persist(Notification::create($recipient->getId(), $type, $payload, new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    /**
     * @return list<array<mixed>|null> the details of each notification, newest first
     */
    private function detailsOf(User $member): array
    {
        $this->loginAs($member);
        $this->client->jsonRequest('GET', '/api/v1/community/notifications');
        self::assertResponseIsSuccessful();
        $items = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($items);
        $details = [];
        foreach ($items as $item) {
            self::assertIsArray($item);
            self::assertArrayHasKey('details', $item);
            $detail = $item['details'];
            self::assertTrue(null === $detail || is_array($detail));
            $details[] = $detail;
        }

        return $details;
    }
}
