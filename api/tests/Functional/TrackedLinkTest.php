<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 55.1: tracked links `/l/{code}`, their admin and the visits the site's redirect records. */
final class TrackedLinkTest extends FunctionalTestCase
{
    public function testTheAdminCreatesListsAndEditsLinks(): void
    {
        $this->loginAsAdmin();
        $inbound = $this->createLink('discord-nov', 'Discord novembre', '/evenements/archilan-4');
        $outbound = $this->createLink('ludo', 'Ludothèque', 'https://ludo.example');

        // Read by id: newest first, and the two may be created in two different seconds.
        $links = array_column($this->adminLinks(), null, 'id');
        self::assertEqualsCanonicalizing([$inbound, $outbound], array_keys($links));
        self::assertSame('inbound', $links[$inbound]['direction']);
        self::assertSame('outbound', $links[$outbound]['direction']);
        self::assertTrue($links[$inbound]['active']);
        self::assertTrue($links[$outbound]['active']);

        $this->client->jsonRequest('PUT', '/api/v1/admin/links/'.$inbound, ['code' => 'ignored', 'label' => 'Discord', 'target' => '/evenements', 'active' => false]);
        self::assertResponseStatusCodeSame(204);
        $link = array_column($this->adminLinks(), null, 'id')[$inbound] ?? null;
        self::assertIsArray($link);
        self::assertSame('discord-nov', $link['code']);
        self::assertSame('/evenements', $link['target']);
        self::assertFalse($link['active']);
    }

    public function testInvalidOrTakenCodesAndTargetsAreRefused(): void
    {
        $this->loginAsAdmin();
        $this->createLink('discord-nov', 'Discord', '/');

        $this->client->jsonRequest('POST', '/api/v1/admin/links', ['code' => 'discord-nov', 'label' => 'Autre', 'target' => '/', 'active' => true]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('tracked_link_code_taken', $this->errorCode());
        $this->client->jsonRequest('POST', '/api/v1/admin/links', ['code' => 'Bad Code', 'label' => 'Autre', 'target' => '/', 'active' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracked_link_code_invalid', $this->errorCode());
        $this->client->jsonRequest('POST', '/api/v1/admin/links', ['code' => 'evil', 'label' => 'Autre', 'target' => '//evil.example', 'active' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('tracked_link_target_invalid', $this->errorCode());
        $this->client->jsonRequest('PUT', '/api/v1/admin/links/0000000000000000000000000000dead', ['label' => 'x', 'target' => '/', 'active' => true]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAnAdminManagesLinks(): void
    {
        $this->client->request('GET', '/api/v1/admin/links');
        self::assertResponseStatusCodeSame(401);
        $this->loginAs($this->createUser('member@example.org'));
        $this->client->jsonRequest('POST', '/api/v1/admin/links', ['code' => 'abc', 'label' => 'x', 'target' => '/', 'active' => true]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAVisitGivesTheTargetAndCountsPerDayAndHost(): void
    {
        $this->loginAsAdmin();
        $id = $this->createLink('discord-nov', 'Discord', '/evenements/archilan-4');
        $this->createLink('off', 'Éteint', '/', false);

        foreach (['discord.com', 'www.discord.com', '', 'not a host'] as $host) {
            $this->client->jsonRequest('POST', '/api/v1/links/discord-nov/visit', ['referrerHost' => $host, 'count' => true]);
            self::assertResponseStatusCodeSame(200);
        }
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('/evenements/archilan-4', $data['target']);
        // A preview or a robot: the target, no count.
        $this->client->jsonRequest('POST', '/api/v1/links/discord-nov/visit', ['referrerHost' => 'discord.com', 'count' => false]);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', '/api/v1/links/off/visit', ['referrerHost' => '', 'count' => true]);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/links/unknown/visit', ['referrerHost' => '', 'count' => true]);
        self::assertResponseStatusCodeSame(404);

        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))->format('Y-m-d');
        $rows = $this->entityManager->getConnection()->createQueryBuilder()
            ->select('day', 'referrer_host', 'visits')
            ->from('tracked_link_visit')
            ->where('link_id = :id')
            ->setParameter('id', $id)
            ->orderBy('referrer_host')
            ->executeQuery()
            ->fetchAllAssociative();
        self::assertSame([
            ['day' => $today, 'referrer_host' => '', 'visits' => 2],
            ['day' => $today, 'referrer_host' => 'discord.com', 'visits' => 2],
        ], $rows);
    }

    /** Story 55.2: the last 7 and 30 days count today; the detail shows 90 days, gaps at zero, and the origins. */
    public function testStatsCountTheLastDaysAndTheOrigins(): void
    {
        $this->loginAsAdmin();
        $id = $this->createLink('discord-nov', 'Discord', '/');
        $quiet = $this->createLink('quiet', 'Sans passage', 'https://ludo.example');
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $day = static fn (int $ago): string => $today->modify(sprintf('-%d days', $ago))->format('Y-m-d');
        foreach ([[0, 'discord.com', 3], [0, '', 1], [6, 't.co', 2], [7, 'discord.com', 4], [29, 'discord.com', 5], [30, '', 6], [90, 't.co', 7]] as [$ago, $host, $visits]) {
            $this->entityManager->getConnection()->insert('tracked_link_visit', ['link_id' => $id, 'day' => $day($ago), 'referrer_host' => $host, 'visits' => $visits]);
        }

        $visits = array_column($this->adminLinks(), 'visits', 'id');
        self::assertSame(['total' => 0, 'last7' => 0, 'last30' => 0], $visits[$quiet]);
        self::assertSame(['total' => 28, 'last7' => 6, 'last30' => 15], $visits[$id]);

        $this->client->request('GET', sprintf('/api/v1/admin/links/%s/stats', $id));
        self::assertResponseStatusCodeSame(200);
        $stats = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($stats);
        $days = $stats['days'];
        self::assertIsArray($days);
        self::assertCount(90, $days);
        self::assertSame(['day' => $day(89), 'visits' => 0], $days[0]);
        self::assertSame(['day' => $day(0), 'visits' => 4], $days[89]);
        self::assertSame(['day' => $day(30), 'visits' => 6], $days[59]);
        // The 91st day back is outside the 90 days.
        self::assertSame([
            ['host' => 'discord.com', 'visits' => 12],
            ['host' => '', 'visits' => 7],
            ['host' => 't.co', 'visits' => 2],
        ], $stats['origins']);

        $this->client->request('GET', '/api/v1/admin/links/0000000000000000000000000000dead/stats');
        self::assertResponseStatusCodeSame(404);
    }

    private function createLink(string $code, string $label, string $target, bool $active = true): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/links', ['code' => $code, 'label' => $label, 'target' => $target, 'active' => $active]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /** @return list<array<mixed>> */
    private function adminLinks(): array
    {
        $this->client->request('GET', '/api/v1/admin/links');
        self::assertResponseStatusCodeSame(200);
        $raw = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($raw);
        $links = [];
        foreach ($raw as $link) {
            self::assertIsArray($link);
            $links[] = $link;
        }

        return $links;
    }

    private function errorCode(): mixed
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;

        return is_array($error) ? ($error['code'] ?? null) : null;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
