<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Application\Query\EventStatsQueryInterface;
use App\Payments\Application\Support\HelloAssoConfig;
use App\Payments\Domain\Entity\HelloAssoOrder;
use App\Registrations\Domain\Entity\Registration;
use App\Shared\Application\Support\StatsPeriod;

/**
 * Story 42.3: the Events section of the admin statistics page.
 */
final class AdminEventStatsTest extends FunctionalTestCase
{
    private const string NOW = '2026-10-03T12:00:00+00:00';

    public function testTheSectionCountsRegistrationsRevenueAndFilling(): void
    {
        // 4 weeks: 09-07, 09-14, 09-21, 09-28 (in progress); the previous period starts on 08-10.
        $lan = $this->createEvent('ArchiLAN #3', new \DateTimeImmutable('2026-09-20T10:00:00+00:00'), new \DateTimeImmutable('2026-09-21T18:00:00+00:00'), capacity: 10, published: true);
        $future = $this->createEvent('LAN d\'hiver', new \DateTimeImmutable('2026-11-01T10:00:00+00:00'), new \DateTimeImmutable('2026-11-02T18:00:00+00:00'), published: true);
        $this->createEvent('Brouillon', new \DateTimeImmutable('2026-12-01T10:00:00+00:00'), new \DateTimeImmutable('2026-12-02T18:00:00+00:00'));
        $old = $this->createEvent('ArchiLAN #2', new \DateTimeImmutable('2026-08-15T10:00:00+00:00'), new \DateTimeImmutable('2026-08-16T18:00:00+00:00'), published: true);

        $this->registrationAt($lan->getId(), 'u1', '2026-09-08T10:00:00+00:00');
        $this->registrationAt($lan->getId(), 'u2', '2026-09-15T10:00:00+00:00', cancelledAt: '2026-09-16T10:00:00+00:00');
        $this->registrationAt($lan->getId(), 'u3', '2026-09-29T10:00:00+00:00');
        $this->registrationAt($old->getId(), 'u1', '2026-08-20T10:00:00+00:00');

        $this->order(1, HelloAssoConfig::FORM_TYPE_EVENT, 1500, '2026-09-10T10:00:00+00:00');
        $this->order(2, HelloAssoConfig::FORM_TYPE_MEMBERSHIP, 1000, '2026-09-30T10:00:00+00:00');
        $this->order(3, HelloAssoConfig::FORM_TYPE_SHOP, 500, '2026-09-30T11:00:00+00:00');
        $this->order(4, HelloAssoConfig::FORM_TYPE_EVENT, 900, null);
        $this->order(5, HelloAssoConfig::FORM_TYPE_EVENT, 700, '2026-08-20T10:00:00+00:00');
        $this->entityManager->flush();

        $stats = $this->query()->stats(StatsPeriod::fromCode('4s', new \DateTimeImmutable(self::NOW)), new \DateTimeImmutable(self::NOW));

        self::assertSame(1, $stats['upcomingEvents'], 'the winter LAN; a draft is not published, the past ones are over');

        self::assertSame([1, 1, 0, 1], array_column($stats['registrations']['series'], 'value'), 'a registration cancelled since still counts where it was made');
        self::assertSame(1, $stats['registrations']['previous']);
        self::assertSame([0, 1, 0, 0], array_column($stats['cancellations']['series'], 'value'));

        self::assertSame(3000, $stats['revenue']['total'], 'cents, paid orders only');
        self::assertSame(700, $stats['revenue']['previous']);
        self::assertSame([1500, 0, 0, 0], array_column($stats['revenueByType']['events']['series'], 'value'));
        self::assertSame([0, 0, 0, 1000], array_column($stats['revenueByType']['memberships']['series'], 'value'));
        self::assertSame([0, 0, 0, 500], array_column($stats['revenueByType']['shop']['series'], 'value'));

        self::assertSame([[
            'eventId' => $lan->getId(),
            'title' => 'ArchiLAN #3',
            'startsAt' => '2026-09-20T10:00:00+00:00',
            'status' => 'published',
            'capacity' => 10,
            'registrations' => 2,
            'fillRate' => 20,
        ]], $stats['events'], 'only the events that start in the period; cancelled registrations leave the filling');
        self::assertNotContains($future->getId(), array_column($stats['events'], 'eventId'));
    }

    public function testTheEndpointIsAdminOnly(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->loginAs($member);
        $this->client->request('GET', '/api/v1/admin/stats/events');
        self::assertResponseStatusCodeSame(403);

        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->loginAs($admin);
        $this->client->request('GET', '/api/v1/admin/stats/events?period=4s');
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertSame([], $body['events'] ?? null);
        $revenue = $body['revenue'] ?? null;
        self::assertIsArray($revenue);
        self::assertSame(0, $revenue['total'] ?? null);
    }

    private function registrationAt(string $eventId, string $userId, string $createdAt, ?string $cancelledAt = null): void
    {
        $registration = $this->createRegistration($eventId, $userId, null === $cancelledAt ? Registration::STATUS_RESERVED : Registration::STATUS_CANCELLED);
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE registration SET created_at = :created, updated_at = :updated WHERE id = :id',
            ['created' => $createdAt, 'updated' => $cancelledAt ?? $createdAt, 'id' => $registration->getId()],
        );
    }

    private function order(int $helloAssoId, string $formType, int $amountCents, ?string $paidAt): void
    {
        $this->entityManager->persist(HelloAssoOrder::fromHelloAsso(
            $helloAssoId,
            $formType,
            'form-'.$helloAssoId,
            null === $paidAt ? 'Waiting' : 'Processed',
            $amountCents,
            null,
            null,
            null,
            null === $paidAt ? null : new \DateTimeImmutable($paidAt),
            new \DateTimeImmutable(self::NOW),
        ));
    }

    private function query(): EventStatsQueryInterface
    {
        $query = self::getContainer()->get(EventStatsQueryInterface::class);
        self::assertInstanceOf(EventStatsQueryInterface::class, $query);

        return $query;
    }
}
