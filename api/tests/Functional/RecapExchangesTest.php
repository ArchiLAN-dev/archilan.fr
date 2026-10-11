<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Friendship;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionFeedEvent;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Entity\SlotBlockEpisode;
use App\Sessions\Domain\Entity\SlotBlockRelease;

/**
 * Story 43.10: « Entre nous » in a session's recap, for those who played it.
 */
final class RecapExchangesTest extends FunctionalTestCase
{
    private const int PROGRESSION = 1;

    private User $viewer;

    private Session $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', displayName: 'Viewer', slug: 'viewer');
        $friend = $this->createUser('friend@example.org', displayName: 'Friend', slug: 'friend');
        $stranger = $this->createUser('stranger@example.org', displayName: 'Stranger', slug: 'stranger');
        $friendship = Friendship::request($friend->getId(), $this->viewer->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);

        $run = Run::create($this->viewer->getId(), 'Run', new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $this->session = Session::createRunning(bin2hex(random_bytes(16)), $run->getId(), 'bridge.local', 38281, 'secret', 5000, new \DateTimeImmutable('2026-09-01T09:00:00+00:00'));
        $this->session->transition(Session::STATUS_FINISHED, new \DateTimeImmutable('2026-09-01T12:00:00+00:00'));
        $this->entityManager->persist($this->session);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        foreach ([[$this->viewer, 'ViewerHK'], [$friend, 'FriendHK'], [$stranger, 'StrangerHK']] as $i => [$player, $slotName]) {
            $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $this->session->getId(), $player->getId(), $game->getId(), $slotName, $i));
        }

        $this->item('ViewerHK', 'FriendHK', '10:00:00', self::PROGRESSION);
        $this->item('ViewerHK', 'FriendHK', '10:01:00', 0);
        $this->item('FriendHK', 'ViewerHK', '10:04:30', self::PROGRESSION);
        $this->item('StrangerHK', 'ViewerHK', '10:30:00', 0);
        $this->item('FriendHK', 'StrangerHK', '10:31:00', self::PROGRESSION);
        $episode = SlotBlockEpisode::open($this->session->getId(), '1', 'ViewerHK', new \DateTimeImmutable('2026-09-01T10:02:00+00:00'));
        $this->entityManager->persist(SlotBlockRelease::of($episode, bin2hex(random_bytes(16)), new \DateTimeImmutable('2026-09-01T10:05:00+00:00')));
        $this->entityManager->flush();
    }

    public function testTheViewerSeesTheirExchangesFriendsFirstAndWhoGotThemOutOfABk(): void
    {
        $data = $this->exchanges($this->viewer);
        self::assertIsArray($data);
        self::assertIsArray($data['exchanges']);

        $rows = [];
        foreach ($data['exchanges'] as $row) {
            self::assertIsArray($row);
            self::assertIsArray($row['players']);
            self::assertIsArray($row['players'][0]);
            $rows[] = [$row['slotName'], $row['players'][0]['slug'], $row['players'][0]['isFriend'], $row['sent'], $row['received'], $row['sentProgression'], $row['receivedProgression']];
        }
        self::assertSame([
            ['FriendHK', 'friend', true, 2, 1, 1, 1],
            ['StrangerHK', 'stranger', false, 0, 1, 0, 0],
        ], $rows, 'friends first; items between two other players are not the viewer\'s');

        self::assertIsArray($data['unblocks']);
        self::assertCount(1, $data['unblocks']);
        $unblock = $data['unblocks'][0];
        self::assertIsArray($unblock);
        self::assertSame(['ViewerHK', 'Hollow Item', 'FriendHK'], [$unblock['slotName'], $unblock['itemName'], $unblock['senderName']]);
        self::assertIsArray($unblock['senders']);
        self::assertIsArray($unblock['senders'][0]);
        self::assertSame('friend', $unblock['senders'][0]['slug']);
    }

    public function testAddingIsOfferedOnlyWithoutARelationship(): void
    {
        $stranger = $this->entityManager->getRepository(User::class)->findOneBy(['slug' => 'stranger']);
        self::assertInstanceOf(User::class, $stranger);
        self::assertSame(['friend' => false, 'stranger' => true], $this->canAdd());

        // Story 43.19: a request already pending, or a block, takes the button away.
        $this->entityManager->persist(Friendship::request($this->viewer->getId(), $stranger->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
        self::assertSame(['friend' => false, 'stranger' => false], $this->canAdd());
    }

    public function testSomeoneWhoDidNotPlayGetsNothing(): void
    {
        self::assertNull($this->exchanges($this->createUser('outsider@example.org', slug: 'outsider')));

        $this->client->restart();
        $this->client->request('GET', '/api/v1/parties/'.$this->session->getId().'/recap/exchanges');
        self::assertResponseStatusCodeSame(401);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> canAdd by slug */
    private function canAdd(): array
    {
        $data = $this->exchanges($this->viewer);
        self::assertIsArray($data);
        self::assertIsArray($data['exchanges']);
        $canAdd = [];
        foreach ($data['exchanges'] as $row) {
            self::assertIsArray($row);
            self::assertIsArray($row['players']);
            foreach ($row['players'] as $player) {
                self::assertIsArray($player);
                self::assertIsString($player['slug']);
                $canAdd[$player['slug']] = $player['canAdd'] ?? null;
            }
        }
        ksort($canAdd);

        return $canAdd;
    }

    private function exchanges(User $viewer): mixed
    {
        $this->loginAs($viewer);
        $this->client->request('GET', '/api/v1/parties/'.$this->session->getId().'/recap/exchanges');
        self::assertResponseStatusCodeSame(200);

        return $this->decodedJsonResponse()['data'] ?? null;
    }

    private function item(string $from, string $to, string $time, int $flags): void
    {
        $this->entityManager->persist(new SessionFeedEvent(
            bin2hex(random_bytes(16)),
            $this->session->getId(),
            SessionFeedEvent::TYPE_ITEM_RECEIVED,
            $from.' sent an item to '.$to,
            new \DateTimeImmutable('2026-09-01T'.$time.'+00:00'),
            1,
            'Hollow Item',
            $flags,
            2,
            'Location',
            1,
            $from,
            'Hollow Knight',
            2,
            $to,
            'Hollow Knight',
        ));
    }
}
