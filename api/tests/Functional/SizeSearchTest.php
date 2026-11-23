<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Application\Handler\RunSizeSearchStepJobHandler;
use App\GameSelection\Application\Message\RunSizeSearchStepJob;
use App\GameSelection\Domain\Entity\Game;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 56.7: an admin designates a game's size options; a player past the target gets a value that fits it, never
 * applied for them.
 */
final class SizeSearchTest extends FunctionalTestCase
{
    private const string YAML = "name: Jean\ngame: Hollow Knight\nHollow Knight:\n  GrubHuntGoal: 46\n  Goal: hollowknight\n  start_inventory: {}\n";

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testAnAdminDesignatesNumericOptionsOnly(): void
    {
        $game = $this->hollowKnight();
        $url = sprintf('/api/v1/admin/games/%s/size-options', $game->getId());

        $this->loginAs($this->createUser('player@example.org'));
        $this->client->jsonRequest('PUT', $url, ['options' => ['GrubHuntGoal']]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        foreach ([['Goal'], ['Unknown'], 'GrubHuntGoal'] as $invalid) {
            $this->client->jsonRequest('PUT', $url, ['options' => $invalid]);
            self::assertResponseStatusCodeSame(422);
        }
        $this->client->jsonRequest('PUT', $url, ['options' => ['GrubHuntGoal']]);
        self::assertResponseIsSuccessful();
        self::assertSame(['GrubHuntGoal'], $this->data()['sizeOptions']);

        $this->client->jsonRequest('PUT', $url, ['options' => []]);
        self::assertSame([], $this->data()['sizeOptions']);
    }

    public function testASearchProposesAValueWithinTheTargetAndAppliesNothing(): void
    {
        // A day's event: 19.2 h. Ten checks an hour, ten checks per grub: 46 grubs is 46 h.
        $event = $this->createEvent('ArchiLAN', new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T10:00:00+00:00'));
        $game = $this->hollowKnight();
        $game->designateSizeOptions(['GrubHuntGoal'], new \DateTimeImmutable());
        $this->entityManager->flush();
        NullRunnerGateway::$slotPreflightSizer = static fn (string $yaml): int => 1 === preg_match('/GrubHuntGoal: (\d+)/', $yaml, $m) ? 10 * (int) $m[1] : 0;
        $player = $this->createUser('player@example.org');
        $this->loginAs($player);

        $this->client->jsonRequest('POST', sprintf('/api/v1/games/%s/size-search', $game->getId()), ['yaml' => self::YAML, 'eventId' => $event->getId()]);
        self::assertResponseStatusCodeSame(202);
        $searchId = $this->data()['searchId'];
        self::assertIsString($searchId);
        $this->runQueuedSteps();

        $this->client->request('GET', sprintf('/api/v1/games/%s/size-search/%s', $game->getId(), $searchId));
        self::assertResponseIsSuccessful();
        $search = $this->data();
        self::assertSame('found', $search['status']);
        self::assertEquals(19.2, $search['targetHours']);
        // 1 (1 h), 23 (23 h), 12 (12 h), 17 (17 h), then 20: 20 h, within 10 % of 19.2.
        self::assertIsArray($search['tries']);
        self::assertSame([1, 23, 12, 17, 20], array_column($search['tries'], 'value'));
        self::assertEquals(['option' => 'GrubHuntGoal', 'from' => 46, 'value' => 20, 'hours' => 20], $search['proposal']);
        // Every try kept the rest of the YAML, empty mappings included.
        $last = NullRunnerGateway::$lastSlotPreflight['playerYaml'] ?? '';
        self::assertStringContainsString('GrubHuntGoal: 20', $last);
        self::assertStringContainsString('start_inventory: {', $last);

        // Someone else cannot read it.
        $this->loginAs($this->createUser('other@example.org'));
        $this->client->request('GET', sprintf('/api/v1/games/%s/size-search/%s', $game->getId(), $searchId));
        self::assertResponseStatusCodeSame(404);
    }

    public function testASearchNeedsSizeOptionsATargetAndAPace(): void
    {
        $event = $this->createEvent('ArchiLAN', new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T10:00:00+00:00'));
        $game = $this->hollowKnight();
        $this->loginAs($this->createUser('player@example.org'));
        $url = sprintf('/api/v1/games/%s/size-search', $game->getId());

        $this->client->jsonRequest('POST', $url, ['yaml' => self::YAML, 'eventId' => $event->getId()]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('no_size_options', $this->errorCode());

        $game->designateSizeOptions(['GrubHuntGoal'], new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->jsonRequest('POST', $url, ['yaml' => self::YAML]);
        self::assertSame('no_target', $this->errorCode());

        // A game without any pace: its sizes cannot be turned into hours.
        $unpaced = $this->createGame('Unpaced', 'unpaced');
        $unpaced->configureApworld('hash-up.apworld', 'hash-up', 'Hollow Knight', 'name: Player
', new \DateTimeImmutable('2026-10-01'));
        $unpaced->recordOptionTypes(['GrubHuntGoal' => ['type' => 'range', 'min' => 1, 'max' => 46, 'default' => 46]]);
        $unpaced->designateSizeOptions(['GrubHuntGoal'], new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->jsonRequest('POST', sprintf('/api/v1/games/%s/size-search', $unpaced->getId()), ['yaml' => self::YAML, 'eventId' => $event->getId()]);
        self::assertSame('unknown_pace', $this->errorCode());
    }

    private function hollowKnight(): Game
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->configureApworld('hash-hk.apworld', 'hash-hk', 'Hollow Knight', "name: Player\n", new \DateTimeImmutable('2026-10-01'));
        $game->recordOptionTypes([
            'Goal' => ['type' => 'choice', 'values' => ['hollowknight', 'radiance', 'any']],
            'GrubHuntGoal' => ['type' => 'range', 'min' => 1, 'max' => 46, 'default' => 46],
        ]);
        $game->estimatePace(10);
        $this->entityManager->flush();

        return $game;
    }

    /** Runs the search's steps as the worker would, delays aside, until it stops asking for more. */
    private function runQueuedSteps(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $handler = self::getContainer()->get(RunSizeSearchStepJobHandler::class);
        self::assertInstanceOf(RunSizeSearchStepJobHandler::class, $handler);
        $done = 0;
        for ($guard = 0; $guard < 50; ++$guard) {
            $steps = array_values(array_filter(
                array_map(static fn ($envelope): object => $envelope->getMessage(), iterator_to_array($transport->getSent(), false)),
                static fn (object $message): bool => $message instanceof RunSizeSearchStepJob,
            ));
            if (count($steps) === $done) {
                break;
            }
            $step = $steps[$done];
            self::assertInstanceOf(RunSizeSearchStepJob::class, $step);
            ++$done;
            $handler($step);
        }
        $this->entityManager->clear();
    }

    private function errorCode(): ?string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;

        return is_array($error) && is_string($error['code'] ?? null) ? $error['code'] : null;
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
