<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Wallet\Application\Command\AwardWelcomeQuests;
use App\Wallet\Application\Query\WelcomeQuestsQueryInterface;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Entity\WalletSetting;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;

/**
 * Story 41.25: the first steps of a newcomer, each paid once for life, read from what was actually played.
 * Test accounts are created on 2026-05-01 (FunctionalTestCase::createUser).
 */
final class WelcomeQuestsTest extends FunctionalTestCase
{
    private const string PLAYED_AT = '2026-10-01T12:00:00+00:00';

    public function testEachStepPaysOnceWhatTheNewcomerReallyDid(): void
    {
        $this->since('2026-04-30T10:00:00+00:00');
        $alice = $this->createUser('alice@example.org', slug: 'alice');
        $bob = $this->createUser('bob@example.org', slug: 'bob');
        $carol = $this->createUser('carol@example.org', slug: 'carol');
        $alice->linkDiscord('111', 'alice', new \DateTimeImmutable());
        // Alice and Bob both make a check in the same session; Alice reaches her goal.
        $this->session('s1', [['Alice', $alice], ['Bob', $bob]], goalOf: 'Alice');
        $this->check('s1', 'Alice');
        $this->check('s1', 'Bob');
        // Carol plays a weekly attempt with a check in its feed, no goal.
        $this->weeklyEntry($carol, 'weekly-s1');
        $this->check('weekly-s1', 'Carol');
        $this->entityManager->flush();

        self::assertSame(8, $this->award()->award(), 'Alice 4 steps, Bob 2, Carol 2');
        self::assertSame(10 + 10 + 15 + 25, $this->paid($alice), 'Discord, check, partner, goal');
        self::assertSame(10 + 15, $this->paid($bob), 'check, partner');
        self::assertSame(10 + 15, $this->paid($carol), 'check, weekly');
        self::assertSame(0, $this->award()->award(), 'never twice');
    }

    public function testADiscordAccountPaysOnceWhicheverAccountItIsLinkedTo(): void
    {
        $this->since('2026-04-30T10:00:00+00:00');
        $first = $this->createUser('first@example.org', slug: 'first');
        $first->linkDiscord('444', 'same', new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame(1, $this->award()->award());

        // Unlinked, then linked to a second account: the Discord account was already paid.
        $first->unlinkDiscord(new \DateTimeImmutable());
        $second = $this->createUser('second@example.org', slug: 'second');
        $this->entityManager->flush();
        $second->linkDiscord('444', 'same', new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame(0, $this->award()->award());
        self::assertSame(0, $this->paid($second));

        // A first account linking another Discord account is not paid twice either.
        $first->linkDiscord('555', 'other', new \DateTimeImmutable());
        $this->entityManager->flush();
        self::assertSame(0, $this->award()->award());
        self::assertSame(10, $this->paid($first));
    }

    public function testABannedOrErasedAccountIsNotEvenLookedAt(): void
    {
        $this->since('2026-04-30T10:00:00+00:00');
        $banned = $this->createUser('banned@example.org', slug: 'banned');
        $banned->linkDiscord('666', 'banned', new \DateTimeImmutable());
        $banned->ban('Triche', new \DateTimeImmutable());
        $erased = $this->createUser('erased@example.org', slug: 'erased');
        $erased->linkDiscord('777', 'erased', new \DateTimeImmutable());
        $erased->anonymizeForDeletion(new \DateTimeImmutable());
        $this->entityManager->flush();

        $query = self::getContainer()->get(WelcomeQuestsQueryInterface::class);
        self::assertInstanceOf(WelcomeQuestsQueryInterface::class, $query);
        self::assertSame([], $query->candidates(new \DateTimeImmutable('2026-04-30T10:00:00+00:00')));
        self::assertSame(0, $this->award()->award());
    }

    public function testAnAccountCreatedBeforeTheWelcomeQuestsHasNone(): void
    {
        $this->since('2026-05-02T10:00:00+00:00');
        $old = $this->createUser('old@example.org', slug: 'old');
        $old->linkDiscord('222', 'old', new \DateTimeImmutable());
        $this->entityManager->flush();

        self::assertSame(0, $this->award()->award());
        $this->loginAs($old);
        $this->client->request('GET', '/api/v1/me/welcome-quests');
        self::assertResponseIsSuccessful();
        self::assertSame(['welcome' => null], $this->decodedJsonResponse());
    }

    public function testTheWalletListsTheStepsUntilAllArePaid(): void
    {
        $this->since('2026-04-30T10:00:00+00:00');
        $dave = $this->createUser('dave@example.org', slug: 'dave');
        $dave->linkDiscord('333', 'dave', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAs($dave);

        $steps = $this->steps();
        self::assertSame(['discord', 'check', 'weekly', 'partner', 'goal'], array_column($steps, 'key'));
        self::assertSame([true, false, false, false, false], array_column($steps, 'done'));
        self::assertSame([false, false, false, false, false], array_column($steps, 'paid'), 'done, paid at the next run');

        $this->award()->award();
        self::assertSame([true, false, false, false, false], array_column($this->steps(), 'paid'));

        foreach (['check', 'weekly', 'partner', 'goal'] as $step) {
            $this->entityManager->persist(PelleMovement::record(
                $dave->getId(), 1, PelleKind::Gold, null, PelleReason::WelcomeReward, 'Premiers pas', null, sprintf('welcome:%s:%s', $step, $dave->getId()), new \DateTimeImmutable(),
            ));
        }
        $this->entityManager->flush();
        $this->client->request('GET', '/api/v1/me/welcome-quests');
        self::assertSame(['welcome' => null], $this->decodedJsonResponse(), 'all paid: the block goes');
    }

    /**
     * @phpstan-impure
     *
     * @return list<array<mixed>>
     */
    private function steps(): array
    {
        $this->client->request('GET', '/api/v1/me/welcome-quests');
        self::assertResponseIsSuccessful();
        $welcome = $this->decodedJsonResponse()['welcome'] ?? null;
        self::assertIsArray($welcome);
        self::assertSame(75, $welcome['total'] ?? null);
        $steps = $welcome['steps'] ?? null;
        self::assertIsArray($steps);
        self::assertTrue(array_is_list($steps));
        $list = [];
        foreach ($steps as $step) {
            self::assertIsArray($step);
            $list[] = $step;
        }

        return $list;
    }

    private function since(string $instant): void
    {
        $this->entityManager->persist(new WalletSetting(WalletSetting::WELCOME_QUESTS_SINCE, new \DateTimeImmutable($instant)->format(\DATE_ATOM)));
        $this->entityManager->flush();
    }

    private function award(): AwardWelcomeQuests
    {
        $award = self::getContainer()->get(AwardWelcomeQuests::class);
        self::assertInstanceOf(AwardWelcomeQuests::class, $award);

        return $award;
    }

    /**
     * @param list<array{0: string, 1: User}> $slots
     */
    private function session(string $sessionId, array $slots, ?string $goalOf = null): void
    {
        $this->entityManager->persist(Session::create($sessionId, 'event-'.$sessionId, new \DateTimeImmutable(self::PLAYED_AT)));
        foreach ($slots as $index => [$name, $user]) {
            $slot = SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $user->getId(), 'game-1', $name, $index + 1, 'slot-'.$sessionId.'-'.$name);
            if ($name === $goalOf) {
                $slot->recordGoal(new \DateTimeImmutable(self::PLAYED_AT));
            }
            $this->entityManager->persist($slot);
        }
    }

    private function check(string $sessionId, string $slotName): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)), $sessionId, SessionFeedEvent::TYPE_ITEM_RECEIVED, 'check', new \DateTimeImmutable(self::PLAYED_AT),
            1, 'Item', 0, 2, 'Location', 1, $slotName, 'Game', 2, 'Someone', 'Game',
        ));
    }

    private function weeklyEntry(User $user, string $sessionId): void
    {
        $at = new \DateTimeImmutable(self::PLAYED_AT);
        $this->entityManager->persist(new WeeklyEntry(
            bin2hex(random_bytes(16)), 'weekly-1', $user->getId(), 1, $at, $at, externalSessionId: $sessionId, launchedAt: $at,
        ));
    }

    private function paid(User $user): int
    {
        return array_sum(array_map(
            static fn (PelleMovement $m): int => $m->getAmount(),
            $this->entityManager->getRepository(PelleMovement::class)->findBy(['userId' => $user->getId(), 'reason' => PelleReason::WelcomeReward]),
        ));
    }
}
