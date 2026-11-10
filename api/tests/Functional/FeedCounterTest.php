<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Command\RecomputeAchievements;
use App\Community\Application\Query\FeedCounterQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementCounter;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Enum\AchievementCounterKind;
use App\Community\Domain\Enum\AchievementCounterMode;
use App\GameSelection\Domain\Entity\Game;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 30.57: the counters of checks and items over the session feed - only the counter's game, once per slot for a
 * location, every copy for an item, co-players and weekly attempts in, releases and collects out.
 */
final class FeedCounterTest extends FunctionalTestCase
{
    private const string OOT = 'Ocarina of Time';
    private const string MORPHA = 'Water Temple Morpha Heart';
    private const string TOKEN = 'Gold Skulltula Token';

    private Game $oot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->oot = $this->game('Ocarina of Time', 'ocarina-of-time', self::OOT, [self::MORPHA, 'Deku Tree Queen Gohma Heart', 'LH Sun'], [self::TOKEN, 'Light Medallion', 'Forest Medallion'], ['Medallions' => ['Light Medallion', 'Forest Medallion']]);
    }

    public function testALocationCountsOncePerSlotForItsPlayersAndNeverFromAnotherGame(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->session('s1');
        $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice');
        $bobSlot = $this->slot('s1', $bob->getId(), 'Bob', 'slot-bob');
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-bob', $alice->getId(), new \DateTimeImmutable('2026-11-01T10:00:00+00:00')));
        $bobSlot->recordHandOver(false, new \DateTimeImmutable('2026-11-01T12:00:00+00:00'));

        $this->line('s1', 'Alice', 'Bob', self::MORPHA, 'Hookshot', '2026-11-01T11:00:00+00:00');
        $this->line('s1', 'Alice', 'Bob', self::MORPHA, 'Hookshot', '2026-11-01T11:00:05+00:00'); // the same check twice
        $this->line('s1', 'Bob', 'Alice', self::MORPHA, 'Bow', '2026-11-01T11:10:00+00:00');      // Alice co-plays Bob's slot
        $this->line('s1', 'Bob', 'Alice', 'LH Sun', 'Bow', '2026-11-01T12:30:00+00:00');          // after Bob's release
        $this->line('s1', 'Alice', 'Alice', self::MORPHA, 'Bow', '2026-11-01T11:20:00+00:00', 'A Link to the Past'); // another game
        // A second session: Morpha again.
        $this->session('s2');
        $this->slot('s2', $alice->getId(), 'Alice', 'slot-alice-2');
        $this->slot('s2', $bob->getId(), 'Bob', 'slot-bob-2');
        $this->line('s2', 'Alice', 'Alice', self::MORPHA, 'Bow', '2026-11-02T11:00:00+00:00');
        $this->entityManager->flush();

        $total = $this->counter(AchievementCounterKind::Location, [self::MORPHA, 'LH Sun'], AchievementCounterMode::Total);
        $most = $this->counter(AchievementCounterKind::Location, [self::MORPHA, 'LH Sun'], AchievementCounterMode::MaxSession);
        $distinct = $this->counter(AchievementCounterKind::Location, [self::MORPHA, 'LH Sun'], AchievementCounterMode::Distinct);
        $values = $this->query()->values($alice->getId(), [$total, $most, $distinct]);

        self::assertSame(3, $values[$total->getId()], 'her slot and Bob\'s in s1, her slot in s2');
        self::assertSame(2, $values[$most->getId()]);
        self::assertSame(1, $values[$distinct->getId()], 'LH Sun came with the release');
        self::assertSame(1, $this->query()->values($bob->getId(), [$total])[$total->getId()], 'his slot in s1; the co-play is Alice\'s');
    }

    public function testItemsCountEveryCopyAndWithOthersOnlyLeavesOutOwnFinds(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $this->session('s1');
        $aliceSlot = $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice');
        $this->slot('s1', $bob->getId(), 'Bob', 'slot-bob');
        $aliceSlot->recordHandOver(true, new \DateTimeImmutable('2026-11-01T13:00:00+00:00'));

        $this->line('s1', 'Alice', 'Alice', 'Kak House', self::TOKEN, '2026-11-01T11:00:00+00:00');       // her own find
        $this->line('s1', 'Bob', 'Alice', 'Bob Location', self::TOKEN, '2026-11-01T11:05:00+00:00', 'Super Metroid'); // from Bob's world
        $this->line('s1', 'Bob', 'Alice', 'Bob Location 2', self::TOKEN, '2026-11-01T11:06:00+00:00', 'Super Metroid');
        $this->line('s1', 'Bob', 'Alice', 'Bob Location 3', self::TOKEN, '2026-11-01T13:30:00+00:00', 'Super Metroid'); // after her collect
        $this->line('s1', 'Bob', 'Bob', 'Bob Location 4', self::TOKEN, '2026-11-01T11:07:00+00:00', 'Super Metroid', 'Super Metroid'); // a token of another game
        $this->entityManager->flush();

        $all = $this->counter(AchievementCounterKind::Item, [self::TOKEN], AchievementCounterMode::Total);
        $fromOthers = $this->counter(AchievementCounterKind::Item, [self::TOKEN], AchievementCounterMode::Total, true);
        $values = $this->query()->values($alice->getId(), [$all, $fromOthers]);

        self::assertSame(3, $values[$all->getId()]);
        self::assertSame(2, $values[$fromOthers->getId()]);
        self::assertSame(0, $this->query()->values($bob->getId(), [$all])[$all->getId()]);
    }

    public function testAWeeklyAttemptCountsUntilItsGoalButNeverWithOthersOnly(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)),
            'weekly-1',
            $alice->getId(),
            1,
            new \DateTimeImmutable('2026-11-01T10:00:00+00:00'),
            new \DateTimeImmutable('2026-11-01T10:00:00+00:00'),
            externalSessionId: 'weekly-session',
            launchedAt: new \DateTimeImmutable('2026-11-01T10:00:00+00:00'),
            goalReachedAt: new \DateTimeImmutable('2026-11-01T12:00:00+00:00'),
        ));
        $this->line('weekly-session', 'Alice', 'Alice', self::MORPHA, 'Bow', '2026-11-01T11:00:00+00:00');
        $this->line('weekly-session', 'Alice', 'Alice', 'LH Sun', 'Bow', '2026-11-01T12:00:01+00:00'); // the release at the goal
        $this->entityManager->flush();

        $counter = $this->counter(AchievementCounterKind::Location, [self::MORPHA, 'LH Sun'], AchievementCounterMode::Total);
        $withOthers = $this->counter(AchievementCounterKind::Location, [self::MORPHA, 'LH Sun'], AchievementCounterMode::Total, true);
        $values = $this->query()->values($alice->getId(), [$counter, $withOthers]);

        self::assertSame(1, $values[$counter->getId()]);
        self::assertSame(0, $values[$withOthers->getId()]);
    }

    public function testAnAchievementOnACounterIsGrantedWithItsQuantity(): void
    {
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $this->session('s1');
        $this->slot('s1', $alice->getId(), 'Alice', 'slot-alice');
        foreach (['Light Medallion', 'Forest Medallion'] as $i => $medallion) {
            $this->line('s1', 'Alice', 'Alice', 'Place '.$i, $medallion, '2026-11-01T11:0'.$i.':00+00:00');
        }
        $counter = $this->counter(AchievementCounterKind::Item, ['Light Medallion', 'Forest Medallion'], AchievementCounterMode::Distinct);
        $now = new \DateTimeImmutable('2026-11-01T10:00:00+00:00');
        $fact = AchievementMetricCatalog::COUNTER_PREFIX.$counter->getId();
        $this->entityManager->persist(AchievementDefinition::create('oot_two', 'Deux médaillons', '', ['op' => 'all', 'rules' => [['fact' => $fact, 'operator' => '>=', 'value' => 2]]], 1, $now));
        $this->entityManager->persist(AchievementDefinition::create('oot_six', 'Les six médaillons', '', ['op' => 'all', 'rules' => [['fact' => $fact, 'operator' => '>=', 'value' => 6]]], 2, $now));
        $this->entityManager->flush();

        $recompute = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $recompute);
        $recompute->recomputeForUser($alice->getId(), notify: false);

        $keys = $this->entityManager->getConnection()->fetchFirstColumn('SELECT achievement_key FROM community_achievement_grant WHERE user_id = ?', [$alice->getId()]);
        self::assertContains('oot_two', $keys);
        self::assertNotContains('oot_six', $keys);
    }

    public function testTheAdminManagesCountersAndAchievementsMustReadARealOne(): void
    {
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'admin'));

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-counters', ['label' => 'Morpha vaincu', 'gameId' => $this->oot->getId(), 'kind' => 'location', 'targets' => [self::MORPHA, ' '.self::MORPHA], 'mode' => 'total']);
        self::assertResponseStatusCodeSame(201);
        $created = $this->dataOf();
        self::assertSame([self::MORPHA], $created['targets']);
        self::assertSame('Ocarina of Time', $created['gameName']);
        $id = $created['id'];
        self::assertIsString($id);

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-counters', ['label' => 'Rien', 'gameId' => $this->oot->getId(), 'kind' => 'location', 'targets' => [], 'mode' => 'total']);
        self::assertResponseStatusCodeSame(422);
        $noName = $this->game('Sans nom AP', 'sans-nom', null, [], [], []);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievement-counters', ['label' => 'X', 'gameId' => $noName->getId(), 'kind' => 'location', 'targets' => ['A'], 'mode' => 'total']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', ['key' => 'oot_ghost', 'name' => 'Fantôme', 'rule' => ['op' => 'all', 'rules' => [['fact' => 'counter:missing', 'operator' => '>=', 'value' => 1]]]]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', '/api/v1/admin/community/achievements', ['key' => 'oot_morpha', 'name' => 'Le Temple de l\'Eau ne m\'a pas eu', 'rule' => ['op' => 'all', 'rules' => [['fact' => 'counter:'.$id, 'operator' => '>=', 'value' => 1]]]]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/admin/community/achievement-counters');
        self::assertResponseIsSuccessful();
        $json = $this->decodedJsonResponse();
        self::assertIsArray($json['data']);
        self::assertIsArray($json['data'][0]);
        self::assertSame(1, $json['data'][0]['usedBy']);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/community/achievement-counters/'.$id);
        self::assertResponseStatusCodeSame(409);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/community/achievement-counters/'.$id, ['label' => 'Morpha battu', 'gameId' => $this->oot->getId(), 'kind' => 'location', 'targets' => [self::MORPHA], 'mode' => 'max_session', 'withOthersOnly' => true]);
        self::assertResponseIsSuccessful();
        $updated = $this->dataOf();
        self::assertSame('max_session', $updated['mode']);
        self::assertTrue($updated['withOthersOnly']);
    }

    public function testTheNamesAreOnlyTheGamesAndFilteredOnWhatTheAdminTyped(): void
    {
        $this->game('A Link to the Past', 'alttp', 'A Link to the Past', ['Water Temple Morpha Heart Fake', 'Sahasrahla'], [], []);
        $this->loginAs($this->createUser('admin@example.org', roles: ['ROLE_USER', 'ROLE_ADMIN'], slug: 'admin'));

        $names = $this->names(['gameId' => $this->oot->getId(), 'kind' => 'location', 'q' => 'WATER mórpha']);
        self::assertSame('introspection', $names['source']);
        self::assertSame([self::MORPHA], $names['names']);

        $hearts = $this->names(['gameId' => $this->oot->getId(), 'kind' => 'location', 'q' => 'he']);
        self::assertSame(['Deku Tree Queen Gohma Heart', self::MORPHA], $hearts['names'], 'a word starting with the text, alphabetical');

        self::assertSame([], $this->names(['gameId' => $this->oot->getId(), 'kind' => 'location', 'q' => 'w'])['names'], 'one character is not enough');
        $picked = $this->names(['gameId' => $this->oot->getId(), 'kind' => 'location', 'q' => 'heart', 'exclude' => [self::MORPHA, 'Deku Tree Queen Gohma Heart', 'Removed Location']]);
        self::assertSame([], $picked['names']);
        self::assertSame(['Removed Location'], $picked['unknown']);

        $items = $this->names(['gameId' => $this->oot->getId(), 'kind' => 'item', 'q' => '']);
        self::assertSame([['name' => 'Medallions', 'items' => ['Light Medallion', 'Forest Medallion']]], $items['groups']);

        // Not introspected: what its sessions put in the feed, for that game only.
        $metroid = $this->game('Super Metroid', 'super-metroid', 'Super Metroid', [], [], []);
        $this->session('s1');
        $this->line('s1', 'Bob', 'Bob', 'Morph Ball', 'Bombs', '2026-11-01T11:00:00+00:00', 'Super Metroid', 'Super Metroid');
        $this->line('s1', 'Bob', 'Bob', 'Morpha Shrine', 'Bombs', '2026-11-01T11:00:00+00:00', self::OOT, self::OOT);
        $this->entityManager->flush();
        $feed = $this->names(['gameId' => $metroid->getId(), 'kind' => 'location', 'q' => 'morph']);
        self::assertSame('feed', $feed['source']);
        self::assertSame(['Morph Ball'], $feed['names']);
    }

    /**
     * @param list<string>                $locations
     * @param list<string>                $items
     * @param array<string, list<string>> $groups
     */
    private function game(string $name, string $slug, ?string $apName, array $locations, array $items, array $groups): Game
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $game = Game::create($name, $slug, 'Description.', null, 'Alt', 'Publisher', Game::AVAILABILITY_AVAILABLE, $now);
        if (null !== $apName) {
            $game->configureApworld('apworlds/'.$slug.'.apworld', str_repeat('a', 64), $apName, 'game: '.$apName, $now);
        }
        $game->recordLocationNames($locations);
        $game->recordItemNames($items, $groups);
        $this->entityManager->persist($game);
        $this->entityManager->flush();

        return $game;
    }

    /**
     * @param list<string> $targets
     */
    private function counter(AchievementCounterKind $kind, array $targets, AchievementCounterMode $mode, bool $withOthersOnly = false): AchievementCounter
    {
        $counter = AchievementCounter::create(bin2hex(random_bytes(16)), 'Compteur', $this->oot->getId(), $kind, $targets, $mode, $withOthersOnly, new \DateTimeImmutable('2026-11-01T10:00:00+00:00'));
        $this->entityManager->persist($counter);
        $this->entityManager->flush();

        return $counter;
    }

    private function session(string $id): void
    {
        $this->entityManager->persist(Session::create($id, 'event-'.$id, new \DateTimeImmutable('2026-11-01T10:00:00+00:00')));
    }

    private function slot(string $session, string $userId, string $name, string $slotId): SessionSlot
    {
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session, $userId, 'game-1', $name, 1, $slotId);
        $this->entityManager->persist($slot);

        return $slot;
    }

    private function line(string $session, string $sender, string $receiver, string $location, string $item, string $at, string $senderGame = self::OOT, string $receiverGame = self::OOT): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $session,
            SessionFeedEvent::TYPE_ITEM_RECEIVED,
            $sender.' sent '.$item.' to '.$receiver,
            new \DateTimeImmutable($at),
            1,
            $item,
            0,
            2,
            $location,
            1,
            $sender,
            $senderGame,
            2,
            $receiver,
            $receiverGame,
        ));
    }

    private function query(): FeedCounterQueryInterface
    {
        $query = self::getContainer()->get(FeedCounterQueryInterface::class);
        self::assertInstanceOf(FeedCounterQueryInterface::class, $query);

        return $query;
    }

    /**
     * @return array<mixed>
     */
    private function dataOf(): array
    {
        $json = $this->decodedJsonResponse();
        self::assertIsArray($json['data']);

        return $json['data'];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<mixed>
     */
    private function names(array $query): array
    {
        $this->client->request('GET', '/api/v1/admin/community/achievement-counters/names?'.http_build_query($query));
        self::assertResponseIsSuccessful();

        return $this->dataOf();
    }
}
