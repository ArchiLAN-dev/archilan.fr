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

        $links = $this->adminLinks();
        self::assertSame([$inbound, $outbound], array_column($links, 'id'));
        self::assertSame(['inbound', 'outbound'], array_column($links, 'direction'));
        self::assertSame([true, true], array_column($links, 'active'));

        $this->client->jsonRequest('PUT', '/api/v1/admin/links/'.$inbound, ['code' => 'ignored', 'label' => 'Discord', 'target' => '/evenements', 'active' => false]);
        self::assertResponseStatusCodeSame(204);
        $links = $this->adminLinks();
        self::assertSame('discord-nov', $links[0]['code']);
        self::assertSame('/evenements', $links[0]['target']);
        self::assertFalse($links[0]['active']);
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
