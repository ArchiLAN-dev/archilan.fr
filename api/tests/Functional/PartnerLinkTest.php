<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use DoctrineMigrations\Version20261117140000;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2).'/migrations/Version20261117140000.php';

/** Story 55.3: every partner has a tracked link that follows it, and the cards lead through it. */
final class PartnerLinkTest extends FunctionalTestCase
{
    public function testAPartnerGetsALinkThatFollowsIt(): void
    {
        $this->loginAsAdmin();
        $id = $this->createPartner('Ludothèque', 'https://ludo.example');
        $twin = $this->createPartner('Ludothèque', 'https://ludo2.example');

        $links = $this->partnerLinks();
        self::assertSame('partenaire-ludotheque', $links[$id]['code']);
        self::assertSame('partenaire-ludotheque-2', $links[$twin]['code']);
        self::assertSame('Partenaire : Ludothèque', $links[$id]['label']);
        self::assertSame('https://ludo.example', $links[$id]['target']);
        self::assertSame('outbound', $links[$id]['direction']);
        self::assertTrue($links[$id]['active']);
        self::assertSame('/l/partenaire-ludotheque', $this->adminPartner($id)['linkPath']);

        // A new address and a hidden partner: the target and the state follow, the code stays.
        $this->client->jsonRequest('PUT', '/api/v1/admin/partners/'.$id, ['name' => 'La Ludo', 'description' => '', 'url' => 'https://ludo.example/fr', 'active' => false, 'logoOnDark' => false]);
        self::assertResponseStatusCodeSame(204);
        $links = $this->partnerLinks();
        self::assertSame('partenaire-ludotheque', $links[$id]['code']);
        self::assertSame('Partenaire : La Ludo', $links[$id]['label']);
        self::assertSame('https://ludo.example/fr', $links[$id]['target']);
        self::assertFalse($links[$id]['active']);
        self::assertNull($this->adminPartner($id)['linkPath']);
    }

    public function testAVisitCountsForThePartnerAndItsLinkIsChangedOnlyThroughIt(): void
    {
        $this->loginAsAdmin();
        $id = $this->createPartner('Boutique', 'https://boutique.example');
        $this->client->jsonRequest('POST', '/api/v1/links/partenaire-boutique/visit', ['referrerHost' => 'archilan.fr', 'count' => true]);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame('https://boutique.example', $data['target']);

        self::assertSame(['total' => 1, 'last7' => 1, 'last30' => 1], $this->adminPartner($id)['visits']);
        $link = $this->partnerLinks()[$id];
        self::assertSame($id, $link['partnerId']);
        self::assertIsString($link['id']);
        $this->client->jsonRequest('PUT', '/api/v1/admin/links/'.$link['id'], ['label' => 'Autre', 'target' => 'https://evil.example', 'active' => true]);
        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('tracked_link_follows_partner', $error['code']);
    }

    public function testDeletingAPartnerTurnsItsLinkOffAndKeepsItsCounts(): void
    {
        $this->loginAsAdmin();
        $id = $this->createPartner('Boutique', 'https://boutique.example');
        $this->client->jsonRequest('POST', '/api/v1/links/partenaire-boutique/visit', ['referrerHost' => '', 'count' => true]);
        self::assertResponseStatusCodeSame(200);

        $this->client->request('DELETE', '/api/v1/admin/partners/'.$id);
        self::assertResponseStatusCodeSame(204);
        $link = $this->partnerLinks()[$id];
        self::assertFalse($link['active']);
        self::assertSame(['total' => 1, 'last7' => 1, 'last30' => 1], $link['visits']);
        $this->client->jsonRequest('POST', '/api/v1/links/partenaire-boutique/visit', ['referrerHost' => '', 'count' => true]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheMigrationGivesALinkToEachPartnerWithoutOne(): void
    {
        $connection = $this->entityManager->getConnection();
        $now = '2026-11-17 12:00:00+00:00';
        foreach ([['p1', 'Ludo', true, 0], ['p2', 'Ludo', false, 1], ['p3', '!!!', true, 2]] as [$id, $name, $active, $position]) {
            $connection->insert('partner', ['id' => str_pad($id, 32, '0'), 'name' => $name, 'description' => '', 'url' => 'https://'.$id.'.example', 'active' => $active, 'position' => $position, 'created_at' => $now, 'updated_at' => $now, 'logo_on_dark' => false], ['active' => 'boolean', 'logo_on_dark' => 'boolean']);
        }
        // A manual link already holds the first code.
        $connection->insert('tracked_link', ['id' => str_repeat('a', 32), 'code' => 'partenaire-ludo', 'label' => 'Manuel', 'target' => '/', 'active' => true, 'created_at' => $now, 'updated_at' => $now], ['active' => 'boolean']);

        $migration = new Version20261117140000($connection, new NullLogger());
        $migration->backfillPartnerLinks();
        $migration->backfillPartnerLinks();

        $rows = $connection->createQueryBuilder()
            ->select('partner_id', 'code', 'target', 'active')
            ->from('tracked_link')
            ->where('partner_id IS NOT NULL')
            ->orderBy('partner_id')
            ->executeQuery()
            ->fetchAllAssociative();
        self::assertSame([
            ['partner_id' => str_pad('p1', 32, '0'), 'code' => 'partenaire-ludo-2', 'target' => 'https://p1.example', 'active' => true],
            ['partner_id' => str_pad('p2', 32, '0'), 'code' => 'partenaire-ludo-3', 'target' => 'https://p2.example', 'active' => false],
            ['partner_id' => str_pad('p3', 32, '0'), 'code' => 'partenaire', 'target' => 'https://p3.example', 'active' => true],
        ], $rows);
    }

    private function createPartner(string $name, string $url): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/partners', ['name' => $name, 'description' => '', 'url' => $url, 'active' => true, 'logoOnDark' => false]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /** @return array<mixed> */
    private function adminPartner(string $id): array
    {
        $this->client->request('GET', '/api/v1/admin/partners');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        foreach ($data as $partner) {
            self::assertIsArray($partner);
            if ($id === ($partner['id'] ?? null)) {
                return $partner;
            }
        }
        self::fail('No partner '.$id);
    }

    /** @return array<string, array<mixed>> the partner links, by partner id */
    private function partnerLinks(): array
    {
        $this->client->request('GET', '/api/v1/admin/links');
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $links = [];
        foreach ($data as $link) {
            self::assertIsArray($link);
            if (is_string($link['partnerId'] ?? null)) {
                $links[$link['partnerId']] = $link;
            }
        }

        return $links;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
