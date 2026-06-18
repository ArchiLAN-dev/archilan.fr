<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\AchievementGrant;
use App\Community\Domain\ActivityEntry;
use App\Identity\Domain\User;

final class CommunityKudosTest extends FunctionalTestCase
{
    public function testToggleAndState(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'achievement', 'targetId' => 'g1']);
        self::assertResponseIsSuccessful();
        self::assertSame(['given' => true, 'count' => 1], $this->data());

        // Toggle off, then on again.
        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'achievement', 'targetId' => 'g1']);
        self::assertSame(['given' => false, 'count' => 0], $this->data());
        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'achievement', 'targetId' => 'g1']);
        self::assertSame(['given' => true, 'count' => 1], $this->data());

        // Invalid target type.
        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'bogus', 'targetId' => 'g1']);
        self::assertResponseStatusCodeSame(422);

        // State: alice gave it, bob didn't (both see count 1).
        $this->client->jsonRequest('POST', '/api/v1/community/kudos/state', ['targets' => [['targetType' => 'achievement', 'targetId' => 'g1']]]);
        $aliceState = $this->data()['achievement:g1'] ?? null;
        self::assertIsArray($aliceState);
        self::assertSame(1, $aliceState['count']);
        self::assertTrue($aliceState['given']);

        $this->loginAs($bob);
        $this->client->jsonRequest('POST', '/api/v1/community/kudos/state', ['targets' => [['targetType' => 'achievement', 'targetId' => 'g1']]]);
        $bobState = $this->data()['achievement:g1'] ?? null;
        self::assertIsArray($bobState);
        self::assertSame(1, $bobState['count']);
        self::assertFalse($bobState['given']);
    }

    public function testFeedRunEntryCarriesKudos(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $entry = ActivityEntry::record($alice->getId(), ActivityEntry::TYPE_RUN_FINISHED, 's1:zelda', new \DateTimeImmutable(), ['game' => 'Zelda', 'sessionId' => 's1']);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        // Bob can kudos his friend Alice's run (but not his own).
        $this->becomeFriends($alice, $bob);

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/community/feed');
        $item = $this->runItem($this->data());
        self::assertSame('run', $item['kudosTargetType']);
        self::assertSame($entry->getId(), $item['kudosTargetId']);
        self::assertSame(0, $item['kudosCount']);
        self::assertFalse($item['viewerHasKudos']);

        // Give kudos on the run entry; the feed reflects it.
        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'run', 'targetId' => $entry->getId()]);
        $toggle = $this->data();
        self::assertSame(['given' => true, 'count' => 1], $toggle);

        $this->client->jsonRequest('GET', '/api/v1/community/feed');
        $item = $this->runItem($this->data());
        self::assertSame(1, $item['kudosCount']);
        self::assertTrue($item['viewerHasKudos']);
    }

    public function testCannotKudosOwnRun(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $entry = ActivityEntry::record($alice->getId(), ActivityEntry::TYPE_RUN_FINISHED, 's1:zelda', new \DateTimeImmutable(), ['game' => 'Zelda', 'sessionId' => 's1']);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        // Alice's own run is not kudos-able in her feed, and toggling it is rejected.
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/feed');
        $own = $this->runItem($this->data());
        self::assertNull($own['kudosTargetType']);
        self::assertNull($own['kudosTargetId']);

        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'run', 'targetId' => $entry->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testCannotKudosOwnAchievement(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $grant = AchievementGrant::grant($alice->getId(), 'first_run', new \DateTimeImmutable());
        $this->entityManager->persist($grant);
        $this->entityManager->flush();

        // On her own profile the grant carries no kudos target.
        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        $achievements = $this->data()['achievements'] ?? null;
        self::assertIsArray($achievements);
        foreach ($achievements as $achievement) {
            self::assertIsArray($achievement);
            self::assertNull($achievement['grantId']);
        }

        $this->client->jsonRequest('POST', '/api/v1/community/kudos', ['targetType' => 'achievement', 'targetId' => $grant->getId()]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testProfileAchievementExposesGrantIdAndKudosCount(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $grant = AchievementGrant::grant($alice->getId(), 'first_run', new \DateTimeImmutable());
        $this->entityManager->persist($grant);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/community/profiles/alice');
        self::assertResponseIsSuccessful();
        $achievements = $this->data()['achievements'] ?? null;
        self::assertIsArray($achievements);

        $byKey = [];
        foreach ($achievements as $achievement) {
            self::assertIsArray($achievement);
            $key = $achievement['key'] ?? null;
            self::assertIsString($key);
            $byKey[$key] = $achievement;
        }
        self::assertSame($grant->getId(), $byKey['first_run']['grantId']);
        self::assertSame(0, $byKey['first_run']['kudosCount']);
        self::assertNull($byKey['veteran']['grantId']);
    }

    /**
     * The single run_finished item in a feed payload.
     *
     * @param array<mixed> $items
     *
     * @return array<string, mixed>
     */
    private function runItem(array $items): array
    {
        foreach ($items as $item) {
            if (is_array($item) && ($item['type'] ?? null) === ActivityEntry::TYPE_RUN_FINISHED) {
                /** @var array<string, mixed> $item */
                return $item;
            }
        }

        self::fail('No run_finished item in the feed.');
    }

    private function becomeFriends(User $a, User $b): void
    {
        $this->loginAs($a);
        $this->client->jsonRequest('POST', '/api/v1/community/profiles/'.$b->getSlug().'/friend-request');
        self::assertResponseIsSuccessful();

        $this->loginAs($b);
        $this->client->jsonRequest('GET', '/api/v1/community/friends');
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $incoming = $data['incoming'] ?? null;
        self::assertIsArray($incoming);
        $first = $incoming[0] ?? null;
        self::assertIsArray($first);
        $friendshipId = $first['friendshipId'] ?? null;
        self::assertIsString($friendshipId);
        $this->client->jsonRequest('POST', '/api/v1/community/friendships/'.$friendshipId.'/accept');
        self::assertResponseStatusCodeSame(204);
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
