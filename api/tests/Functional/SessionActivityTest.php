<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\Identity\Domain\User;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;

final class SessionActivityTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
            $this->entityManager->getClassMetadata(Game::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testActivityWithOccurredAtSetsLastActivityAtToThatValue(): void
    {
        $session = $this->createRunningSession();

        $occurredAt = '2026-05-12T10:00:00+00:00';

        $this->client->request(
            'PATCH',
            sprintf('/api/v1/sessions/%s/activity', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['activityType' => 'check', 'occurredAt' => $occurredAt], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['ok']);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertNotNull($reloaded->getLastActivityAt());
        self::assertSame($occurredAt, $reloaded->getLastActivityAt()->format(\DateTimeInterface::ATOM));
    }

    public function testActivityWithoutOccurredAtSetsLastActivityAtToApproximatelyNow(): void
    {
        $session = $this->createRunningSession();

        $before = new \DateTimeImmutable();

        $this->client->request(
            'PATCH',
            sprintf('/api/v1/sessions/%s/activity', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['activityType' => 'item'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);

        $after = new \DateTimeImmutable();

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        $lastActivity = $reloaded->getLastActivityAt();
        self::assertNotNull($lastActivity);
        self::assertGreaterThanOrEqual($before->getTimestamp(), $lastActivity->getTimestamp());
        self::assertLessThanOrEqual($after->getTimestamp() + 1, $lastActivity->getTimestamp());
    }

    public function testUnknownActivityTypeIsAcceptedAndReturns200(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'PATCH',
            sprintf('/api/v1/sessions/%s/activity', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['activityType' => 'some_future_unknown_event_type'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testTransitionToRunningBackfillsLastActivityAtFromStartedAt(): void
    {
        $session = Session::create(bin2hex(random_bytes(16)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable('2026-05-12T09:00:00+00:00');
        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 9042, 'secret');
        $this->entityManager->flush();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Session::class, $session->getId());
        self::assertInstanceOf(Session::class, $reloaded);
        self::assertNotNull($reloaded->getLastActivityAt());
        self::assertNotNull($reloaded->getStartedAt());
        // Both set to the same $now
        self::assertSame(
            $reloaded->getStartedAt()->getTimestamp(),
            $reloaded->getLastActivityAt()->getTimestamp(),
        );
    }

    public function testUnknownSessionIdReturns404(): void
    {
        $this->client->request(
            'PATCH',
            '/api/v1/sessions/does-not-exist/activity',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer test-bridge-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['activityType' => 'check'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testMissingTokenReturns401(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'PATCH',
            sprintf('/api/v1/sessions/%s/activity', $session->getId()),
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['activityType' => 'check'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidTokenReturns401(): void
    {
        $session = $this->createRunningSession();

        $this->client->request(
            'PATCH',
            sprintf('/api/v1/sessions/%s/activity', $session->getId()),
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer wrong-token', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['activityType' => 'check'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(401);
    }

    private function createRunningSession(): Session
    {
        $session = Session::create(bin2hex(random_bytes(16)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();
        foreach ([
            Session::STATUS_VALIDATING,
            Session::STATUS_READY,
            Session::STATUS_GENERATING,
            Session::STATUS_GENERATED,
            Session::STATUS_LAUNCHING,
        ] as $status) {
            $session->transition($status, $now);
        }
        $session->transition(Session::STATUS_RUNNING, $now, '10.0.0.1', 9042, 'secret');
        $this->entityManager->flush();

        return $session;
    }
}
