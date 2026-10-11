<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Application\Command\RecomputeAchievements;
use App\Community\Application\Message\RecomputeAchievementsForUserMessage;
use App\Community\Application\Query\SocialPlayQueryInterface;
use App\Community\Domain\AchievementMetricCatalog;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Entity\Friendship;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Community\Domain\Repository\AchievementGrantRepositoryInterface;
use App\Community\Domain\Service\SocialAchievementDefinitions;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\WeeklyRuns\Domain\Entity\WeeklyDuel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 43.16: achievements for playing with others, on facts read from the history.
 */
final class SocialAchievementsTest extends FunctionalTestCase
{
    private string $gameId;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gameId = $this->createGame('Game', 'game-slug')->getId();
        $this->me = $this->member('me');
    }

    public function testTheFactsCountWhoThePlayerPlayedWith(): void
    {
        $alice = $this->member('alice');
        $bob = $this->member('bob');
        $carol = $this->member('carol');
        $this->befriend($alice);
        $this->befriend($bob);

        $this->playedRun([$alice, $bob], finished: true);
        $this->playedRun([$alice], finished: true);
        $this->playedRun([$alice, $carol], finished: false);
        $this->playedRun([$this->member('elsewhere')], finished: true, owner: $carol);
        $this->entityManager->flush();

        $facts = $this->play()->forUser($this->me->getId());

        self::assertSame(3, $facts['coplayers'], 'alice, bob and carol');
        self::assertSame(2, $facts['friends'], 'alice and bob only');
        self::assertSame(2, $facts['maxFinishedWithSamePerson'], 'two finished with alice, the third still going');
        self::assertSame(0, $facts['weeklyDuelsWon']);
    }

    public function testTheSocialAchievementsAreGrantedFromTheHistoryAndNotified(): void
    {
        $friends = array_map($this->member(...), ['f1', 'f2', 'f3', 'f4', 'f5']);
        foreach ($friends as $friend) {
            $this->befriend($friend);
        }
        $this->playedRun($friends, finished: true);
        $this->playedRun([$friends[0]], finished: true);
        $duel = WeeklyDuel::open(bin2hex(random_bytes(8)), $this->me->getId(), new \DateTimeImmutable());
        $duel->resolve($this->me->getId(), new \DateTimeImmutable());
        $this->entityManager->persist($duel);
        $this->entityManager->flush();

        $definitions = self::getContainer()->get(AchievementDefinitionRepositoryInterface::class);
        self::assertInstanceOf(AchievementDefinitionRepositoryInterface::class, $definitions);
        foreach (SocialAchievementDefinitions::all() as $position => $definition) {
            $definitions->save(AchievementDefinition::create($definition['key'], $definition['name'], $definition['description'], $definition['rule'], $position + 1, new \DateTimeImmutable()));
        }

        $recompute = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $recompute);
        self::assertSame(2, $recompute->recomputeForUser($this->me->getId()));

        $grants = self::getContainer()->get(AchievementGrantRepositoryInterface::class);
        self::assertInstanceOf(AchievementGrantRepositoryInterface::class, $grants);
        $keys = $grants->grantedKeys($this->me->getId());
        sort($keys);
        self::assertSame(['friends_played_5', 'weekly_duel_won'], $keys, 'two finished with f1 is not three');
        self::assertCount(2, $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->me->getId(), 'type' => 'achievement_unlocked']));
    }

    public function testTheRecomputeCommandTurnsTheSeededAchievementsOnAndNotifies(): void
    {
        $definitions = self::getContainer()->get(AchievementDefinitionRepositoryInterface::class);
        self::assertInstanceOf(AchievementDefinitionRepositoryInterface::class, $definitions);
        foreach (SocialAchievementDefinitions::all() as $position => $definition) {
            $seeded = AchievementDefinition::create($definition['key'], $definition['name'], $definition['description'], $definition['rule'], $position + 1, new \DateTimeImmutable());
            $seeded->deactivate(new \DateTimeImmutable());
            $definitions->save($seeded);
        }
        $duel = WeeklyDuel::open(bin2hex(random_bytes(8)), $this->me->getId(), new \DateTimeImmutable());
        $duel->resolve($this->me->getId(), new \DateTimeImmutable());
        $this->entityManager->persist($duel);
        $this->entityManager->flush();

        // Seeded inactive (story 43.18): the silent hourly pass grants nothing yet.
        $recompute = self::getContainer()->get(RecomputeAchievements::class);
        self::assertInstanceOf(RecomputeAchievements::class, $recompute);
        self::assertSame(0, $recompute->recomputeForUser($this->me->getId(), notify: false));

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('community:achievements:recompute'));
        $tester->execute(['--notify' => true, '--activate' => 'friends_played_5,same_partner_3,weekly_duel_won']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Activated 3 achievement(s).', $tester->getDisplay());

        $notices = $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->me->getId(), 'type' => 'achievement_unlocked']);
        self::assertCount(1, $notices, 'the duel won, notified');
    }

    public function testAcceptingAFriendRecomputesBothMembersAchievements(): void
    {
        $alice = $this->member('alice');
        $friendship = Friendship::request($alice->getId(), $this->me->getId(), new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        $this->loginAs($this->me);
        $this->client->request('POST', '/api/v1/community/friendships/'.$friendship->getId().'/accept');
        self::assertResponseIsSuccessful();

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $recomputed = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RecomputeAchievementsForUserMessage) {
                $recomputed[] = $message->userId;
            }
        }
        self::assertEqualsCanonicalizing([$this->me->getId(), $alice->getId()], $recomputed);
    }

    public function testEverySocialAchievementRestsOnAKnownFact(): void
    {
        foreach (SocialAchievementDefinitions::all() as $definition) {
            $rules = $definition['rule']['rules'] ?? null;
            self::assertIsArray($rules);
            self::assertIsArray($rules[0] ?? null);
            self::assertIsString($rules[0]['fact'] ?? null);
            self::assertTrue(AchievementMetricCatalog::isValidFact($rules[0]['fact']), $definition['key']);
        }
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function member(string $slug): User
    {
        return $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
    }

    private function befriend(User $other): void
    {
        $friendship = Friendship::request($this->me->getId(), $other->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();
    }

    /**
     * A personal run of the owner (the viewer by default) with one slot each.
     *
     * @param list<User> $players
     */
    private function playedRun(array $players, bool $finished, ?User $owner = null): void
    {
        $owner ??= $this->me;
        $now = new \DateTimeImmutable('-2 days');
        $run = Run::create($owner->getId(), 'Run', $now);
        $session = Session::createRunning(bin2hex(random_bytes(16)), $run->getId(), 'ap.local', 38281, null, null, $now);
        if ($finished) {
            $session->transition(Session::STATUS_FINISHED, $now->modify('+1 hour'));
        }
        $this->entityManager->persist($session);
        $run->attachSession($session->getId());
        $this->entityManager->persist($run);
        foreach ([$owner, ...$players] as $player) {
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $player->getId(), $this->gameId, $player->getDisplayName(), 0));
        }
    }

    private function play(): SocialPlayQueryInterface
    {
        $play = self::getContainer()->get(SocialPlayQueryInterface::class);
        self::assertInstanceOf(SocialPlayQueryInterface::class, $play);

        return $play;
    }
}
