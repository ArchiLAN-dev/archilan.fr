<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 27.12: a weekly attempt shows its spheres when the weekly's template allows it, items always stripped.
 */
final class WeeklyRunSlotSpheresTest extends FunctionalTestCase
{
    private User $player;
    private string $runId;
    private string $entryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $now = new \DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: 'template-1',
            weekYear: 2026,
            weekNumber: 40,
            seed: 'archilan-weekly-2026-40',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
        $this->entityManager->persist($run);
        $entry = new WeeklyEntry(
            bin2hex(random_bytes(16)),
            $run->getId(),
            $this->player->getId(),
            1,
            $now,
            $now,
            externalSessionId: 'ext-session-1',
            launchedAt: $now,
            bridgePort: 45000,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        $this->runId = $run->getId();
        $this->entryId = $entry->getId();

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $cave = ['id' => 1, 'name' => 'Cave', 'item' => ['name' => 'Sword']];
        $httpClient->setResponseFactory(new MockResponse(
            (string) json_encode(['reachable_unchecked' => [$cave], 'spheres' => [['index' => 0, 'locations' => [$cave]]]]),
            ['http_code' => 200],
        ));
        $this->loginAs($this->player);
    }

    public function testThePlayerSeesTheirSpheresWithoutTheirItemsByDefault(): void
    {
        $this->client->jsonRequest('GET', $this->url());

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        self::assertTrue($body['spheresVisible'] ?? null);
        $data = $body['data'] ?? null;
        self::assertIsArray($data);
        self::assertSame([['index' => 0, 'locations' => [['id' => 1, 'name' => 'Cave']]]], $data['spheres'] ?? null);
    }

    public function testATemplateThatHidesThemDropsTheSpheres(): void
    {
        $set = self::getContainer()->get(SetSessionConfigOverride::class);
        self::assertInstanceOf(SetSessionConfigOverride::class, $set);
        $set->execute('template-1', ['spheresVisible' => false]);

        $this->client->jsonRequest('GET', $this->url());

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        self::assertFalse($body['spheresVisible'] ?? null);
        $data = $body['data'] ?? null;
        self::assertIsArray($data);
        self::assertArrayNotHasKey('spheres', $data);
        self::assertSame([['id' => 1, 'name' => 'Cave']], $data['reachable_unchecked'] ?? null);
    }

    private function url(): string
    {
        return sprintf('/api/v1/weekly-runs/%s/entries/%s/slots/1/reachable', $this->runId, $this->entryId);
    }
}
