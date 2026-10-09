<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Block;
use App\Community\Domain\Entity\Friendship;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Entity\EventPrivateAccessLog;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;

/**
 * Story 43.4: « N de tes amis participent », on an event and grouped on the list of upcoming events.
 */
final class EventFriendsTest extends FunctionalTestCase
{
    private User $viewer;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->viewer = $this->createUser('viewer@example.org', ['ROLE_USER'], 'Viewer', 'viewer');
        $this->event = $this->publishedEvent('LAN', true);
    }

    public function testReservedFriendsCountSubmittedOrNotAndCancelledOnesDoNot(): void
    {
        $reserved = $this->friend('reserved');
        $submitted = $this->friend('submitted');
        $cancelled = $this->friend('cancelled');
        $stranger = $this->createUser('stranger@example.org', ['ROLE_USER'], 'Stranger', 'stranger');
        $pending = $this->createUser('pending@example.org', ['ROLE_USER'], 'Pending', 'pending');
        $this->entityManager->persist(Friendship::request($this->viewer->getId(), $pending->getId(), new \DateTimeImmutable()));

        $this->createRegistration($this->event->getId(), $reserved->getId());
        $this->createRegistration($this->event->getId(), $submitted->getId())->confirm(new \DateTimeImmutable());
        $this->createRegistration($this->event->getId(), $cancelled->getId(), Registration::STATUS_CANCELLED);
        $this->createRegistration($this->event->getId(), $stranger->getId());
        $this->createRegistration($this->event->getId(), $pending->getId());
        $this->entityManager->flush();

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/events/'.$this->event->getId().'/friends');
        self::assertResponseStatusCodeSame(200);
        $slugs = self::slugs($this->decodedJsonResponse()['data']);
        sort($slugs);
        self::assertSame(['reserved', 'submitted'], $slugs);
    }

    public function testABlockEitherWayHidesTheFriend(): void
    {
        $blocked = $this->friend('blocked');
        $this->createRegistration($this->event->getId(), $blocked->getId());
        $this->entityManager->persist(Block::create($blocked->getId(), $this->viewer->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/events/'.$this->event->getId().'/friends');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data']);
    }

    public function testThePrivateEventShowsFriendsOnlyToThoseLetIn(): void
    {
        $private = $this->publishedEvent('Privée', false);
        $this->createRegistration($private->getId(), $this->friend('inside')->getId());

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/events/'.$private->getId().'/friends');
        self::assertSame([], $this->decodedJsonResponse()['data'], 'no access to the event: nothing');

        $this->entityManager->persist(new EventPrivateAccessLog(bin2hex(random_bytes(16)), $private->getId(), $this->viewer->getId(), true, new \DateTimeImmutable()));
        $this->entityManager->flush();
        $this->client->request('GET', '/api/v1/events/'.$private->getId().'/friends');
        self::assertSame(['inside'], self::slugs($this->decodedJsonResponse()['data']));
    }

    public function testTheListIsReadInOneGroupedCall(): void
    {
        $other = $this->publishedEvent('Autre', true);
        $empty = $this->publishedEvent('Vide', true);
        $alice = $this->friend('alice');
        $this->createRegistration($this->event->getId(), $alice->getId());
        $this->createRegistration($other->getId(), $alice->getId());
        $this->createRegistration($other->getId(), $this->friend('bob')->getId());

        $this->loginAs($this->viewer);
        $this->client->request('GET', '/api/v1/community/event-friends?ids='.implode(',', [$this->event->getId(), $other->getId(), $empty->getId()]));
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $expected = [$this->event->getId(), $other->getId()];
        $keys = array_keys($data);
        sort($expected);
        sort($keys);
        self::assertSame($expected, $keys, 'an event without a friend is left out');
        self::assertSame(['alice'], self::slugs($data[$this->event->getId()]));
        self::assertCount(2, self::slugs($data[$other->getId()]));

        $this->client->request('GET', '/api/v1/community/event-friends?ids='.$empty->getId());
        self::assertStringContainsString('"data":{}', (string) $this->client->getResponse()->getContent(), 'an object, even empty');
    }

    public function testAnAnonymousVisitorGetsNothing(): void
    {
        $this->client->request('GET', '/api/v1/events/'.$this->event->getId().'/friends');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/v1/community/event-friends?ids='.$this->event->getId());
        self::assertResponseStatusCodeSame(401);
    }

    // ─── helpers ────────────────────────────────────────────────────────────────

    private function publishedEvent(string $title, bool $isPublic): Event
    {
        return $this->createEvent($title, new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T18:00:00+00:00'), published: true, isPublic: $isPublic);
    }

    private function friend(string $slug): User
    {
        $user = $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
        $friendship = Friendship::request($user->getId(), $this->viewer->getId(), new \DateTimeImmutable());
        $friendship->accept(new \DateTimeImmutable());
        $this->entityManager->persist($friendship);
        $this->entityManager->flush();

        return $user;
    }

    /** @return list<string> */
    private static function slugs(mixed $cards): array
    {
        self::assertIsArray($cards);
        $slugs = [];
        foreach ($cards as $card) {
            self::assertIsArray($card);
            self::assertIsString($card['slug']);
            $slugs[] = $card['slug'];
        }

        return $slugs;
    }
}
