<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use App\Wallet\Domain\Entity\QuestDefinition;
use App\Wallet\Domain\Enum\QuestMetric;
use App\Wallet\Domain\ValueObject\QuestObjective;
use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Story 41.28: cosmetics won through achievements and quests - a reward access nobody can buy, given once, with
 * where it came from.
 */
final class CosmeticRewardTest extends FunctionalTestCase
{
    private const string IN_THE_WEEK = '2026-10-01T12:00:00+00:00';

    public function testAnAchievementUnlocksATitleTheMemberWearsWithItsOrigin(): void
    {
        $this->asAdmin();
        $this->client->jsonRequest('POST', '/api/v1/admin/profile-titles', ['key' => 'phil', 'label' => 'Phil Connors', 'access' => 'reward', 'rarity' => 'epic']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'title', 'cosmeticKey' => 'phil', 'price' => 50]);
        self::assertResponseStatusCodeSame(422, 'a reward is never sold');

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', $this->achievement('jour_sans_fin', 'Un jour sans fin', ['type' => 'title', 'key' => 'nope']));
        self::assertResponseStatusCodeSame(422, 'an unknown cosmetic is refused');
        $achievementId = $this->createAchievement('jour_sans_fin', 'Un jour sans fin', ['type' => 'title', 'key' => 'phil']);

        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member', slug: 'mem');
        $this->loginAs($member);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => 'phil']);
        self::assertResponseStatusCodeSame(422, 'to win first');

        $this->asAdmin('admin2@example.org');
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$achievementId.'/grants', ['slug' => 'mem']);
        self::assertResponseStatusCodeSame(201);

        $owned = $this->owned($member);
        self::assertCount(1, $owned);
        self::assertSame(['title', 'phil', 'achievement', 'Un jour sans fin'], [$owned[0]->getType(), $owned[0]->getCosmeticKey(), $owned[0]->getSource(), $owned[0]->getSourceLabel()]);
        $notification = $this->entityManager->getRepository(Notification::class)->findOneBy(['recipientId' => $member->getId(), 'type' => Notification::TYPE_COSMETIC_UNLOCKED]);
        self::assertInstanceOf(Notification::class, $notification);
        self::assertSame('Titre « Phil Connors »', $notification->getPayload()['label'] ?? null);

        $this->loginAs($member);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['title' => 'phil']);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/mem');
        $profile = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($profile);
        self::assertSame(['label' => 'Phil Connors', 'rarity' => 'epic', 'icon' => null, 'access' => 'reward', 'origin' => 'Succès « Un jour sans fin »'], $profile['title'] ?? null);
    }

    public function testTheMembersHoldingAnAchievementGetTheCosmeticItNowUnlocks(): void
    {
        $this->asAdmin();
        $achievementId = $this->createAchievement('fidele', 'Fidèle', null);
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member', slug: 'mem');
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements/'.$achievementId.'/grants', ['slug' => 'mem']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([], $this->owned($member));

        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievements/'.$achievementId, [...$this->achievement('fidele', 'Fidèle', ['type' => 'color', 'key' => 'ruby'])]);
        self::assertResponseIsSuccessful();
        $owned = $this->owned($member);
        self::assertCount(1, $owned);
        self::assertSame(['color', 'ruby', 'achievement'], [$owned[0]->getType(), $owned[0]->getCosmeticKey(), $owned[0]->getSource()]);

        // Saved again unchanged: nothing given twice.
        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievements/'.$achievementId, [...$this->achievement('fidele', 'Fidèle', ['type' => 'color', 'key' => 'ruby'])]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->owned($member));

        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievements');
        $body = $this->decodedJsonResponse();
        self::assertStringContainsString('"label":"Couleur de pseudo « Rubis »"', (string) json_encode($body, \JSON_UNESCAPED_UNICODE));
    }

    public function testAQuestUnlocksItsCosmeticTheFirstTimeOnly(): void
    {
        $this->asAdmin();
        $this->client->jsonRequest('POST', '/api/v1/admin/quests', ['title' => 'Faux', 'description' => '', 'reward' => 10, 'objectives' => [['metric' => 'checks', 'target' => 1]], 'inDraw' => true, 'cosmetic' => ['type' => 'color', 'key' => 'gold']]);
        self::assertResponseStatusCodeSame(422, 'an unknown colour');

        $quest = QuestDefinition::write('Un check', '', 30, [new QuestObjective(QuestMetric::Checks, 1)], true, 1, new \DateTimeImmutable('2026-09-01T12:00:00+00:00'), 'q-check');
        $quest->unlocks('color', 'lime');
        $this->entityManager->persist($quest);
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $this->entityManager->persist(Session::create('s-1', 'event-s-1', new \DateTimeImmutable(self::IN_THE_WEEK)));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), 's-1', $alice->getId(), 'game-1', 'Alice', 1, 'slot-s-1-Alice'));
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)), 's-1', SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', new \DateTimeImmutable(self::IN_THE_WEEK),
            1, 'Item', 0, 2, 'Location', 1, 'Alice', 'Game', 2, 'Someone', 'Game',
        ));
        $this->entityManager->flush();

        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertStringContainsString('"cosmetic":{"type":"color","key":"lime","label":"Couleur de pseudo « Lime »"}', (string) json_encode($this->decodedJsonResponse(), \JSON_UNESCAPED_UNICODE));

        $week = QuestWeek::containing(new \DateTimeImmutable(self::IN_THE_WEEK));
        $this->award()->awardWeek($week);
        $owned = $this->owned($alice);
        self::assertCount(1, $owned);
        self::assertSame(['color', 'lime', 'quest', 'Un check'], [$owned[0]->getType(), $owned[0]->getCosmeticKey(), $owned[0]->getSource(), $owned[0]->getSourceLabel()]);

        $this->award()->awardWeek($week->next());
        $this->award()->awardWeek($week);
        self::assertCount(1, $this->owned($alice), 'given once');
        self::assertCount(1, $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $alice->getId(), 'type' => Notification::TYPE_COSMETIC_UNLOCKED]));
    }

    private function asAdmin(string $email = 'admin@example.org'): void
    {
        $this->loginAs($this->createUser($email, ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
    }

    /**
     * @param array{type: string, key: string}|null $reward
     *
     * @return array<string, mixed>
     */
    private function achievement(string $key, string $name, ?array $reward): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'description' => '',
            'rule' => ['op' => 'all', 'rules' => [['fact' => 'runs', 'operator' => '>=', 'value' => 1000]]],
            'reward' => $reward,
        ];
    }

    /**
     * @param array{type: string, key: string}|null $reward
     */
    private function createAchievement(string $key, string $name, ?array $reward): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', $this->achievement($key, $name, $reward));
        self::assertResponseStatusCodeSame(201);
        $body = $this->decodedJsonResponse();
        $created = is_array($body['data'] ?? null) ? $body['data'] : $body;
        self::assertIsString($created['id'] ?? null);

        return $created['id'];
    }

    /** @return list<OwnedCosmetic> */
    private function owned(User $member): array
    {
        $this->entityManager->clear();

        return $this->entityManager->getRepository(OwnedCosmetic::class)->findBy(['userId' => $member->getId()]);
    }

    private function award(): AwardWeeklyQuests
    {
        $award = self::getContainer()->get(AwardWeeklyQuests::class);
        self::assertInstanceOf(AwardWeeklyQuests::class, $award);

        return $award;
    }
}
