<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;

/**
 * Story 56.5: how long a world should last (an event's target, by default its length minus 20 %, and a private
 * run's), the YAML test measured against it, and the option presets a player applies.
 */
final class TargetDurationAndPresetsTest extends FunctionalTestCase
{
    private const string YAML = "name: Jean\ngame: Hollow Knight\nHollow Knight:\n  Goal: hollowknight\n";

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

    public function testAnEventTargetsItsLengthMinusAFifthUntilAnAdminSetsOne(): void
    {
        $event = $this->createEvent('ArchiLAN', new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T10:00:00+00:00'));
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $url = sprintf('/api/v1/admin/events/%s', $event->getId());

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertNull($this->data()['targetHours']);
        self::assertSame(19.2, $this->data()['effectiveTargetHours']);

        $this->client->jsonRequest('PATCH', $url, [...$this->eventPayload(), 'targetHours' => 12]);
        self::assertResponseIsSuccessful();
        self::assertSame(12, $this->data()['targetHours']);
        self::assertEquals(12, $this->data()['effectiveTargetHours']);

        // A save that does not send it keeps it.
        $this->client->jsonRequest('PATCH', $url, $this->eventPayload());
        self::assertSame(12, $this->data()['targetHours']);

        $this->client->jsonRequest('PATCH', $url, [...$this->eventPayload(), 'targetHours' => 5000]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PATCH', $url, [...$this->eventPayload(), 'targetHours' => null]);
        self::assertNull($this->data()['targetHours']);
    }

    public function testThePlayersTestSaysItsDurationAgainstTheEventsTarget(): void
    {
        $event = $this->createEvent('ArchiLAN', new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T10:00:00+00:00'));
        $game = $this->hollowKnight();
        $game->estimatePace(10);
        $this->entityManager->flush();
        NullRunnerGateway::$slotPreflightResult = ['status' => 'passed', 'error' => '', 'measures' => [
            ['name' => 'Jean', 'game' => 'Hollow Knight', 'locations' => 283, 'ownProgression' => 196, 'hostedProgression' => 0],
        ]];
        $this->loginAs($this->createUser('player@example.org'));
        $ticket = $this->startTest($game);

        $this->client->request('GET', sprintf('/api/v1/games/%s/yaml-test/%s?eventId=%s', $game->getId(), $ticket, $event->getId()));
        self::assertResponseIsSuccessful();
        $estimate = $this->data()['estimate'];
        self::assertIsArray($estimate);
        self::assertSame(283, $estimate['locations']);
        self::assertSame(28.3, $estimate['hours']);
        self::assertSame('admin', $estimate['source']);
        self::assertSame(19.2, $estimate['targetHours']);
        self::assertTrue($estimate['overTarget']);

        // Without a context, the duration alone.
        $this->client->request('GET', sprintf('/api/v1/games/%s/yaml-test/%s', $game->getId(), $ticket));
        $estimate = $this->data()['estimate'];
        self::assertIsArray($estimate);
        self::assertNull($estimate['targetHours']);
        self::assertFalse($estimate['overTarget']);
    }

    public function testARunsTargetIsSetByItsOrganisersAndReadByItsPlayers(): void
    {
        $owner = $this->createUser('owner@example.org');
        $guest = $this->createUser('guest@example.org');
        $stranger = $this->createUser('stranger@example.org');
        $game = $this->hollowKnight();
        $now = new \DateTimeImmutable();
        $run = Run::create($owner->getId(), 'Test Run', $now);
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $guest->getId(), $now));
        $this->entityManager->flush();
        $url = sprintf('/api/v1/runs/%s/target-hours', $run->getId());

        $this->loginAs($guest);
        $this->client->jsonRequest('PUT', $url, ['targetHours' => 6]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner);
        $this->client->jsonRequest('PUT', $url, ['targetHours' => 0]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PUT', $url, ['targetHours' => 6]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', sprintf('/api/v1/runs/%s', $run->getId()));
        self::assertSame(6, $this->data()['targetHours']);

        NullRunnerGateway::$slotPreflightResult = ['status' => 'passed', 'error' => '', 'measures' => [
            ['name' => 'Jean', 'game' => 'Hollow Knight', 'locations' => 30, 'ownProgression' => 5, 'hostedProgression' => 0],
        ]];
        $this->loginAs($guest);
        $ticket = $this->startTest($game);
        $this->client->request('GET', sprintf('/api/v1/games/%s/yaml-test/%s?runId=%s', $game->getId(), $ticket, $run->getId()));
        $estimate = $this->data()['estimate'];
        self::assertIsArray($estimate);
        self::assertEquals(6, $estimate['targetHours']);
        // No pace for the game: the size only, never over a target.
        self::assertNull($estimate['hours']);
        self::assertFalse($estimate['overTarget']);

        $this->loginAs($stranger);
        $ticket = $this->startTest($game);
        $this->client->request('GET', sprintf('/api/v1/games/%s/yaml-test/%s?runId=%s', $game->getId(), $ticket, $run->getId()));
        $estimate = $this->data()['estimate'];
        self::assertIsArray($estimate);
        self::assertNull($estimate['targetHours']);
    }

    public function testAnAdminDefinesPresetsCheckedAgainstTheOptions(): void
    {
        $game = $this->hollowKnight();
        $game->recordOptionTypes([
            'Goal' => ['type' => 'choice', 'values' => ['hollowknight', 'radiance', 'any']],
            'RandomizeGrubs' => ['type' => 'toggle'],
            'GrubHuntGoal' => ['type' => 'range', 'min' => 1, 'max' => 46, 'default' => 46],
            'StartLocation' => ['type' => 'text'],
        ]);
        $this->entityManager->flush();
        $url = sprintf('/api/v1/admin/games/%s/option-presets', $game->getId());

        $this->loginAs($this->createUser('player@example.org'));
        $this->client->jsonRequest('PUT', $url, ['presets' => []]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_ADMIN']));
        $this->client->jsonRequest('PUT', $url, ['presets' => [
            ['name' => 'Court', 'values' => ['Goal' => 'hollowknight', 'RandomizeGrubs' => false, 'GrubHuntGoal' => 10]],
            ['name' => 'Long', 'values' => ['Goal' => 'radiance', 'RandomizeGrubs' => true]],
        ]]);
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['name' => 'Court', 'values' => ['Goal' => 'hollowknight', 'RandomizeGrubs' => false, 'GrubHuntGoal' => 10]],
            ['name' => 'Long', 'values' => ['Goal' => 'radiance', 'RandomizeGrubs' => true]],
        ], $this->data()['optionPresets']);

        foreach ([
            [['name' => 'A', 'values' => ['Goal' => 'nope']]],
            [['name' => 'A', 'values' => ['GrubHuntGoal' => 99]]],
            [['name' => 'A', 'values' => ['StartLocation' => 'x']]],
            [['name' => 'A', 'values' => ['Unknown' => 1]]],
            [['name' => 'A', 'values' => ['Goal' => 'any']], ['name' => 'a', 'values' => ['Goal' => 'any']]],
            array_fill(0, 4, ['name' => 'A', 'values' => ['Goal' => 'any']]),
        ] as $invalid) {
            $this->client->jsonRequest('PUT', $url, ['presets' => $invalid]);
            self::assertResponseStatusCodeSame(422);
        }

        $this->client->jsonRequest('PUT', $url, ['presets' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->data()['optionPresets']);
    }

    private function hollowKnight(): Game
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->configureApworld('hash-hk.apworld', 'hash-hk', 'Hollow Knight', "name: Player\n", new \DateTimeImmutable('2026-10-01'));
        $this->entityManager->flush();

        return $game;
    }

    private function startTest(Game $game): string
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/games/%s/yaml-test', $game->getId()), ['yaml' => self::YAML]);
        self::assertResponseStatusCodeSame(202);
        $ticket = $this->data()['ticket'];
        self::assertIsString($ticket);

        return $ticket;
    }

    /** @return array<string, mixed> */
    private function eventPayload(): array
    {
        return [
            'title' => 'ArchiLAN',
            'description' => 'Une session Archipelago.',
            'startsAt' => '2027-06-01T10:00:00+00:00',
            'endsAt' => '2027-06-02T10:00:00+00:00',
            'venue' => 'Clermont-Ferrand',
            'capacity' => 50,
            'registrationOpensAt' => '2027-05-01T10:00:00+00:00',
            'registrationClosesAt' => '2027-05-30T18:00:00+00:00',
            'isPublic' => true,
        ];
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
