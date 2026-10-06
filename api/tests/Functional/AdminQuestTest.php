<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWeeklyQuests;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\ValueObject\QuestWeek;

/**
 * Story 41.15: the admins write the weekly quests, set how many a week, and plan the current and coming weeks.
 */
final class AdminQuestTest extends FunctionalTestCase
{
    public function testAnAdminWritesAQuestAndTheCurrentWeekDrawsIt(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        $id = $this->write(['title' => 'Marathon', 'description' => 'Joue beaucoup.', 'reward' => 60, 'inDraw' => true, 'objectives' => [
            ['metric' => 'checks', 'target' => 50],
            ['metric' => 'sessions', 'target' => 2],
        ]]);

        $overview = $this->overview();
        self::assertSame(3, $overview['questsPerWeek'] ?? null);
        $quests = $overview['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertIsArray($quests[0] ?? null);
        self::assertSame([['metric' => 'checks', 'target' => 50], ['metric' => 'sessions', 'target' => 2]], $quests[0]['objectives'] ?? null);

        $weeks = $this->weeks($overview);
        self::assertCount(9, $weeks, 'the current week and 8 coming ones');
        self::assertTrue($weeks[0]['current'] ?? null);
        self::assertTrue($weeks[0]['drawn'] ?? null);
        self::assertSame([['questId' => $id, 'title' => 'Marathon', 'reward' => 60, 'origin' => 'drawn', 'retired' => false]], $weeks[0]['quests'] ?? null);
        self::assertFalse($weeks[1]['drawn'] ?? null, 'a coming week is drawn when it starts');
    }

    public function testAQuestMustBePayableAndCountable(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        foreach ([
            ['title' => '', 'reward' => 10, 'objectives' => [['metric' => 'goals', 'target' => 1]]],
            ['title' => 'Trop', 'reward' => 5000, 'objectives' => [['metric' => 'goals', 'target' => 1]]],
            ['title' => 'Vide', 'reward' => 10, 'objectives' => []],
            ['title' => 'Inconnu', 'reward' => 10, 'objectives' => [['metric' => 'kudos', 'target' => 1]]],
            ['title' => 'Double', 'reward' => 10, 'objectives' => [['metric' => 'goals', 'target' => 1], ['metric' => 'goals', 'target' => 2]]],
        ] as $payload) {
            $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, (string) json_encode($payload));
        }

        $this->client->request('PUT', '/api/v1/admin/quests-settings', [], [], ['CONTENT_TYPE' => 'application/json'], '{"questsPerWeek": 11}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/api/v1/admin/quests-settings', [], [], ['CONTENT_TYPE' => 'application/json'], '{"questsPerWeek": 5}');
        self::assertResponseStatusCodeSame(204);
        self::assertSame(5, $this->overview()['questsPerWeek'] ?? null);
    }

    public function testAQuestOutOfTheDrawIsPinnedToAComingWeekAndRetiringItUnpinsIt(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $special = $this->write(['title' => 'Spéciale LAN', 'reward' => 100, 'inDraw' => false, 'objectives' => [['metric' => 'goals', 'target' => 1]]]);
        $other = $this->write(['title' => 'Autre', 'reward' => 20, 'inDraw' => false, 'objectives' => [['metric' => 'checks', 'target' => 10]]]);
        $next = QuestWeek::containing(new \DateTimeImmutable())->next()->key;

        $this->pin($next, ['questId' => $special]);
        self::assertResponseStatusCodeSame(204);
        $this->pin($next, ['questId' => $special]);
        self::assertResponseStatusCodeSame(409, 'already in the week');

        $weeks = $this->weeks($this->overview());
        self::assertSame([], $weeks[0]['quests'] ?? null, 'out of the draw: the current week does not draw it');
        self::assertSame('pinned', $this->served($weeks[1])[0]['origin'] ?? null);

        // Replaced by another quest, at the same place.
        $this->pin($next, ['questId' => $other, 'replaces' => $special]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$other], array_column($this->served($this->weeks($this->overview())[1]), 'questId'));

        // Retired: its pins on the weeks not drawn yet go.
        $this->client->request('POST', sprintf('/api/v1/admin/quests/%s/retire', $other));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->weeks($this->overview())[1]['quests'] ?? null);
        $this->pin($next, ['questId' => $other]);
        self::assertResponseStatusCodeSame(409, 'a retired quest no longer pins');

        $this->pin(QuestWeek::containing(new \DateTimeImmutable())->previous()->key, ['questId' => $special]);
        self::assertResponseStatusCodeSame(422, 'a past week is history');
        $this->client->request('DELETE', sprintf('/api/v1/admin/quest-weeks/%s/quests/%s', $next, $special));
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCurrentWeekCanBeChangedByHand(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));
        $drawn = $this->write(['title' => 'Tirée', 'reward' => 30, 'inDraw' => true, 'objectives' => [['metric' => 'goals', 'target' => 1]]]);
        $imposed = $this->write(['title' => 'Imposée', 'reward' => 30, 'inDraw' => false, 'objectives' => [['metric' => 'weeklies', 'target' => 1]]]);
        $current = QuestWeek::containing(new \DateTimeImmutable())->key;
        self::assertSame([$drawn], array_column($this->served($this->weeks($this->overview())[0]), 'questId'));

        $this->pin($current, ['questId' => $imposed, 'replaces' => $drawn]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([$imposed], array_column($this->served($this->weeks($this->overview())[0]), 'questId'), 'not drawn again');

        $this->client->request('DELETE', sprintf('/api/v1/admin/quest-weeks/%s/quests/%s', $current, $imposed));
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->weeks($this->overview())[0]['quests'] ?? null);
    }

    public function testTheAdminSetsTheChestAndSeesWhatEachQuestPaid(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $id = $this->write(['title' => 'Un goal', 'reward' => 40, 'inDraw' => true, 'objectives' => [['metric' => 'goals', 'target' => 1]]]);
        self::assertSame(50, $this->overview()['chestReward'] ?? null, 'a chest by default');

        $this->client->request('PUT', '/api/v1/admin/quests-settings', [], [], ['CONTENT_TYPE' => 'application/json'], '{"chestReward": 1001}');
        self::assertResponseStatusCodeSame(422);
        $this->client->request('PUT', '/api/v1/admin/quests-settings', [], [], ['CONTENT_TYPE' => 'application/json'], '{"chestReward": 80}');
        self::assertResponseStatusCodeSame(204);

        // Last week served the quest, and the admin's own slot reached its goal then.
        $lastWeek = QuestWeek::containing(new \DateTimeImmutable())->previous();
        $this->entityManager->persist(Session::create('s-1', 'event-1', $lastWeek->start));
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), 's-1', $admin->getId(), 'game-1', 'Admin', 1, 'slot-1');
        $slot->recordGoal($lastWeek->start->modify('+1 day'));
        $this->entityManager->persist($slot);
        $this->entityManager->flush();
        $award = self::getContainer()->get(AwardWeeklyQuests::class);
        self::assertInstanceOf(AwardWeeklyQuests::class, $award);
        self::assertSame(2, $award->awardWeek($lastWeek), 'the quest and the chest');

        $overview = $this->overview();
        self::assertSame(80, $overview['chestReward'] ?? null);
        $quests = $overview['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertIsArray($quests[0] ?? null);
        self::assertSame(['weeksServed' => 2, 'lastWeek' => $lastWeek->key, 'lastMembers' => 1, 'pelles' => 40], $quests[0]['stats'] ?? null, 'last week and this one');

        $past = $overview['pastWeeks'] ?? null;
        self::assertIsArray($past);
        self::assertCount(4, $past);
        self::assertIsArray($past[0]);
        self::assertSame($lastWeek->key, $past[0]['key'] ?? null);
        self::assertSame([['questId' => $id, 'title' => 'Un goal', 'reward' => 40, 'origin' => 'drawn', 'retired' => false, 'members' => 1, 'pelles' => 40]], $past[0]['quests'] ?? null);
        self::assertSame(1, $past[0]['chests'] ?? null);
        self::assertSame(120, $past[0]['pelles'] ?? null);
    }

    public function testAWeekPaidBeforeTheQuestsWereRecordedComesBackFromTheLedger(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $id = $this->write(['title' => 'Un goal', 'reward' => 40, 'inDraw' => false, 'objectives' => [['metric' => 'goals', 'target' => 1]]]);
        // Three weeks ago, before story 41.15: a payment, and no served entry.
        $old = QuestWeek::containing(new \DateTimeImmutable())->previous()->previous()->previous();
        $this->entityManager->persist(PelleMovement::record(
            $admin->getId(), 40, PelleKind::Gold, null, PelleReason::QuestReward, 'Quête : Un goal', null,
            sprintf('quest:%s:%s:%s', $old->key, $id, $admin->getId()), new \DateTimeImmutable(),
        ));
        $this->entityManager->flush();

        $past = $this->overview()['pastWeeks'] ?? null;
        self::assertIsArray($past);
        self::assertIsArray($past[2] ?? null);
        self::assertSame($old->key, $past[2]['key'] ?? null);
        self::assertSame([['questId' => $id, 'title' => 'Un goal', 'reward' => 40, 'origin' => 'drawn', 'retired' => false, 'members' => 1, 'pelles' => 40]], $past[2]['quests'] ?? null);
        self::assertSame(40, $past[2]['pelles'] ?? null);
    }

    public function testAnObjectiveAimsAtAGamePlayedOnTheSiteAndTheMemberSeesItsName(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $never = $this->createGame('Jamais joué', 'jamais-joue');
        $this->entityManager->persist(Session::create('s-1', 'event-1', new \DateTimeImmutable()));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), 's-1', $admin->getId(), $game->getId(), 'Admin', 1, 'slot-1'));
        $this->entityManager->flush();

        $scopes = $this->overview()['scopes'] ?? null;
        self::assertIsArray($scopes);
        self::assertSame([['id' => $game->getId(), 'name' => 'Hollow Knight']], $scopes['games'] ?? null, 'only the games played on the site');

        $aimed = static fn (string $gameId): array => ['title' => 'Sur HK', 'reward' => 40, 'inDraw' => true, 'drawWeight' => 3, 'objectives' => [
            ['metric' => 'goals', 'target' => 1, 'scope' => 'game', 'scopeId' => $gameId],
        ]];
        foreach ([
            $aimed($never->getId()),
            ['title' => 'Mauvais type', 'reward' => 40, 'objectives' => [['metric' => 'weeklies', 'target' => 1, 'scope' => 'game', 'scopeId' => $game->getId()]]],
            ['title' => 'Trop lourd', 'reward' => 40, 'drawWeight' => 6, 'objectives' => [['metric' => 'goals', 'target' => 1]]],
        ] as $payload) {
            $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(422, (string) json_encode($payload));
        }

        $this->write($aimed($game->getId()));
        $quests = $this->overview()['quests'] ?? null;
        self::assertIsArray($quests);
        self::assertIsArray($quests[0] ?? null);
        self::assertSame(3, $quests[0]['drawWeight'] ?? null);

        // The week drew it: the member sees the game by its name.
        $this->client->request('GET', '/api/v1/me/quests');
        self::assertResponseIsSuccessful();
        $mine = $this->decodedJsonResponse()['quests'] ?? null;
        self::assertIsArray($mine);
        self::assertIsArray($mine[0] ?? null);
        $objectives = $mine[0]['objectives'] ?? null;
        self::assertIsArray($objectives);
        self::assertIsArray($objectives[0] ?? null);
        self::assertSame('Hollow Knight', $objectives[0]['scope'] ?? null);
    }

    public function testOnlyAnAdminManagesTheQuests(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER'], 'Member'));

        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function write(array $payload): string
    {
        $this->client->request('POST', '/api/v1/admin/quests', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(201);
        $id = $this->decodedJsonResponse()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pin(string $weekKey, array $payload): void
    {
        $this->client->request('POST', sprintf('/api/v1/admin/quest-weeks/%s/quests', $weekKey), [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /** @return array<mixed> */
    private function overview(): array
    {
        $this->client->request('GET', '/api/v1/admin/quests');
        self::assertResponseIsSuccessful();

        return $this->decodedJsonResponse();
    }

    /**
     * @param array<mixed> $overview
     *
     * @return list<array<mixed>>
     */
    private function weeks(array $overview): array
    {
        $weeks = $overview['weeks'] ?? null;
        self::assertIsArray($weeks);

        return array_values(array_filter($weeks, is_array(...)));
    }

    /**
     * @param array<mixed> $week
     *
     * @return list<array<mixed>>
     */
    private function served(array $week): array
    {
        $quests = $week['quests'] ?? null;
        self::assertIsArray($quests);

        return array_values(array_filter($quests, is_array(...)));
    }
}
