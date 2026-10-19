<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\Identity\Domain\Entity\User;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;

/**
 * Story 9.57: a player tests the YAML of an option editor against the game's current apworld.
 */
final class GameYamlTestControllerTest extends FunctionalTestCase
{
    private const string YAML = "name: Jean\ngame: Hollow Knight\nHollow Knight:\n  Goal: hollowknight\n";

    private User $player;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();

        $this->player = $this->createUser('player@example.org');
        $this->game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->game->configureApworld('hash-hk.apworld', 'hash-hk', 'Hollow Knight', "name: Player\n", new \DateTimeImmutable('2026-10-01'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testTheYamlIsGeneratedAgainstTheCurrentApworld(): void
    {
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame(['playerYaml' => self::YAML, 'apworldHash' => 'hash-hk', 'requiredFileIds' => []], NullRunnerGateway::$lastSlotPreflight);
        self::assertStringStartsWith('null-preflight-job.', $this->ticket());
    }

    public function testThePlayerReadsTheResultWithTheTicket(): void
    {
        NullRunnerGateway::$slotPreflightResult = ['status' => 'failed', 'error' => "Traceback (most recent call last):\nOptions.OptionError: Goal is not a valid choice"];
        $this->loginAs($this->player);
        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);
        $ticket = $this->ticket();

        $this->client->request('GET', $this->uri().'/'.$ticket);

        self::assertResponseStatusCodeSame(200);
        $data = $this->data();
        self::assertSame('failed', $data['status'] ?? null);
        self::assertStringContainsString('Goal is not a valid choice', (string) json_encode($data['error'] ?? null));
    }

    public function testAnotherPlayerCannotReadTheResult(): void
    {
        $this->loginAs($this->player);
        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);
        $ticket = $this->ticket();

        $this->loginAs($this->createUser('other@example.org'));
        $this->client->request('GET', $this->uri().'/'.$ticket);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->uri().'/null-preflight-job');
        self::assertResponseStatusCodeSame(404, 'a bare job id is not a ticket');
    }

    public function testATicketIsBoundToItsGame(): void
    {
        $other = $this->createGame('Celeste', 'celeste');
        $this->loginAs($this->player);
        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);
        $ticket = $this->ticket();

        $this->client->request('GET', sprintf('/api/v1/games/%s/yaml-test/%s', $other->getId(), $ticket));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAGameThatReadsAFileIsLeftToTheSlotTest(): void
    {
        $this->game->markRequiredAtGeneration('HKSettings.rom_file', 'Hollow Knight.sfc', 'sfc', null, []);
        $this->entityManager->flush();
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);

        self::assertResponseStatusCodeSame(409);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);
    }

    public function testTheTestNeedsAYamlAGameAndARunner(): void
    {
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => '  ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => str_repeat('a', 100 * 1024 + 1)]);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', '/api/v1/games/unknown/yaml-test', ['yaml' => self::YAML]);
        self::assertResponseStatusCodeSame(404);

        $bare = $this->createGame('Celeste', 'celeste');
        $this->client->jsonRequest('POST', sprintf('/api/v1/games/%s/yaml-test', $bare->getId()), ['yaml' => self::YAML]);
        self::assertResponseStatusCodeSame(404, 'no apworld configured');

        NullRunnerGateway::$slotPreflightUnavailable = true;
        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);
        self::assertResponseStatusCodeSame(503);
    }

    public function testAnAnonymousVisitorIsRefused(): void
    {
        $this->client->jsonRequest('POST', $this->uri(), ['yaml' => self::YAML]);

        self::assertResponseStatusCodeSame(401);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);
    }

    private function uri(): string
    {
        return sprintf('/api/v1/games/%s/yaml-test', $this->game->getId());
    }

    private function ticket(): string
    {
        $ticket = $this->data()['ticket'] ?? null;
        self::assertIsString($ticket);

        return $ticket;
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
