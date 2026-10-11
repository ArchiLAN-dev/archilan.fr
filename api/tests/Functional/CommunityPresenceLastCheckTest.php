<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotCoPlayer;

/**
 * Story 30.45. "En jeu" follows the last check: the game shown is the one whose slot was checked most recently,
 * the member's own slots before the ones they co-play, and a slot quiet for 30 minutes no longer counts.
 */
final class CommunityPresenceLastCheckTest extends FunctionalTestCase
{
    private const string SECRET = 'test-runner-secret'; // matches CENTRAL_API_SECRET in .env.test

    public function testTheGameShownIsTheOneWithTheMostRecentCheck(): void
    {
        $jean = $this->createUser('jean@example.org', slug: 'jean');
        [$session, $reg] = $this->runningSessionWith($jean, '-2 hours');
        $this->slot($session, $reg->getId(), 'Luigi Mansion', 'JeanLM', '-10 minutes');
        $this->slot($session, $reg->getId(), 'Sayonara Wild Hearts', 'JeanSWH', '-2 minutes');

        self::assertSame('Sayonara Wild Hearts', $this->presence('jean')['game']);
    }

    public function testAGameWithoutARecentCheckNoLongerCounts(): void
    {
        $jean = $this->createUser('jeanne@example.org', slug: 'jeanne');
        [$session, $reg] = $this->runningSessionWith($jean, '-5 hours');
        // The run is still running, but nothing has happened on this slot for an hour.
        $this->slot($session, $reg->getId(), 'Minecraft Dig', 'JeanneMD', '-1 hour');

        $presence = $this->presence('jeanne');
        self::assertFalse($presence['playing']);
        self::assertNull($presence['game']);
    }

    public function testAFreshlyStartedRunCountsBeforeItsFirstCheck(): void
    {
        $jean = $this->createUser('jo@example.org', slug: 'jo');
        [$session, $reg] = $this->runningSessionWith($jean, '-5 minutes');
        $this->slot($session, $reg->getId(), 'Hollow Knight', 'JoHK', null);

        self::assertSame('Hollow Knight', $this->presence('jo')['game']);
    }

    public function testAGoalReachedSlotIsNotPlayedOnceTheWindowIsOver(): void
    {
        $jean = $this->createUser('jim@example.org', slug: 'jim');
        [$session, $reg] = $this->runningSessionWith($jean, '-2 hours');
        $done = $this->slot($session, $reg->getId(), 'Minecraft Dig', 'JimMD', '-1 minute');
        // Story 43.7: a goal reached within the half hour still shows, « Objectif atteint ».
        $done->recordGoal(new \DateTimeImmutable('-1 hour'));
        $this->entityManager->flush();

        self::assertFalse($this->presence('jim')['playing']);
    }

    public function testOwnSlotsComeBeforeCoPlayedOnes(): void
    {
        $jean = $this->createUser('jea@example.org', slug: 'jea');
        $other = $this->createUser('max@example.org', slug: 'max');
        [$session, $jeanReg] = $this->runningSessionWith($jean, '-1 hour');
        $otherReg = $this->createRegistration($session->getEventId(), $other->getId());
        $this->slot($session, $jeanReg->getId(), 'Luigi Mansion', 'JeaLM', '-8 minutes');
        // Jean co-plays Max's Minecraft Dig, where Max just made a check.
        $minecraft = $this->slot($session, $otherReg->getId(), 'Minecraft Dig', 'MaxMD', '-1 minute', 'slot-mc');
        $this->entityManager->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), 'slot-mc', $jean->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertSame('Luigi Mansion', $this->presence('jea')['game'], 'his own recent slot wins');
        self::assertSame('Minecraft Dig', $this->presence('max')['game']);

        // Once his own slot goes quiet, the co-played one shows.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE session_slot SET last_check_at = NOW() - INTERVAL '2 hours' WHERE slot_name = 'JeaLM'",
        );
        self::assertSame('Minecraft Dig', $this->presence('jea')['game']);
        self::assertSame($minecraft->getSessionId(), $this->presence('jea')['sessionId']);
    }

    public function testABridgePushDatesTheSlotsWhoseChecksGrew(): void
    {
        $jean = $this->createUser('jeanot@example.org', slug: 'jeanot');
        [$session, $reg] = $this->runningSessionWith($jean, '-3 hours');
        $this->slot($session, $reg->getId(), 'Luigi Mansion', 'JLM', '-2 hours');
        $this->slot($session, $reg->getId(), 'Sayonara Wild Hearts', 'JSWH', '-2 hours');
        self::assertFalse($this->presence('jeanot')['playing']);

        $uri = sprintf('/api/v1/internal/sessions/%s/players-push', $session->getId());
        $push = static fn (int $lm, int $swh): array => ['slots' => [
            '1' => ['slot_name' => 'JLM', 'checks_done' => $lm],
            '2' => ['slot_name' => 'JSWH', 'checks_done' => $swh],
        ]];
        $this->client->jsonRequest('POST', $uri, $push(10, 5), ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        $this->client->jsonRequest('POST', $uri, $push(11, 5), ['HTTP_X_INTERNAL_SECRET' => self::SECRET]);
        self::assertResponseIsSuccessful();

        self::assertSame('Luigi Mansion', $this->presence('jeanot')['game']);
    }

    /**
     * @return array{Session, \App\Registrations\Domain\Entity\Registration}
     */
    private function runningSessionWith(User $user, string $startedAgo): array
    {
        $now = new \DateTimeImmutable();
        $event = $this->createEvent('LAN', $now->modify('-1 day'), $now->modify('+1 day'), 20);
        $reg = $this->createRegistration($event->getId(), $user->getId());
        $at = new \DateTimeImmutable($startedAgo);
        $session = Session::create(bin2hex(random_bytes(16)), $event->getId(), $at);
        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED, Session::STATUS_LAUNCHING] as $status) {
            $session->transition($status, $at);
        }
        $session->transition(Session::STATUS_RUNNING, $at, 'bridge.local', 38281, 'secret', 5000);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return [$session, $reg];
    }

    private function slot(Session $session, string $registrationId, string $gameName, string $slotName, ?string $lastCheckAgo, ?string $slotId = null): SessionSlot
    {
        $game = $this->createGame($gameName, strtolower(str_replace(' ', '-', $gameName)).'-'.bin2hex(random_bytes(3)));
        $slot = SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $registrationId, $game->getId(), $slotName, 0, $slotId);
        if (null !== $lastCheckAgo) {
            $slot->recordCheckActivity(new \DateTimeImmutable($lastCheckAgo));
        }
        $this->entityManager->persist($slot);
        $this->entityManager->flush();

        return $slot;
    }

    /**
     * @return array<mixed>
     */
    private function presence(string $slug): array
    {
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/'.$slug);
        self::assertResponseIsSuccessful();
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $presence = $data['presence'] ?? null;
        self::assertIsArray($presence);

        return $presence;
    }
}
