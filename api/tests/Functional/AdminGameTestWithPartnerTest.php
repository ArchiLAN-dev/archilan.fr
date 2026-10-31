<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\Identity\Domain\Entity\User;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 38.30: a meta-game (Tombola) places its progression in the other worlds, so it cannot generate solo. An
 * admin turns on its test with a partner player, and every test the API starts for it then asks for one.
 */
final class AdminGameTestWithPartnerTest extends FunctionalTestCase
{
    private User $admin;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();

        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean', slug: 'jean');
        $this->game = $this->createGame('Tombola', 'tombola');
        $this->game->configureApworld('hash-tombola.apworld', 'hash-tombola', 'Tombola', "tombola\n", new \DateTimeImmutable('2026-10-01'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testTheAdminTurnsOnTheTestWithAPartner(): void
    {
        $this->loginAs($this->admin);

        $this->changeTestPartner(true);

        self::assertResponseStatusCodeSame(200);
        self::assertTrue($this->gameData()['testWithPartner'] ?? null);
        $this->entityManager->clear();
        $game = $this->entityManager->find(Game::class, $this->game->getId());
        self::assertInstanceOf(Game::class, $game);
        self::assertTrue($game->testsWithPartner());

        $this->changeTestPartner(false);

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($this->gameData()['testWithPartner'] ?? null);
    }

    public function testANonBooleanIsRefused(): void
    {
        $this->loginAs($this->admin);

        $this->changeTestPartner('yes');

        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyAnAdminChangesIt(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER'], 'Membre', slug: 'membre'));

        $this->changeTestPartner(true);

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheRerunOfTheServedVersionAsksForAPartner(): void
    {
        $this->game->changeTestPartner(true);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-preflight', $this->game->getId()));

        self::assertResponseStatusCodeSame(202);
        self::assertSame([['hash' => 'hash-tombola', 'requiredFileIds' => [], 'withPartner' => true]], NullRunnerGateway::$apworldPreflightRuns);
    }

    public function testTheUploadOfANewVersionAsksForAPartner(): void
    {
        $this->game->changeTestPartner(true);
        $this->entityManager->flush();
        NullRunnerGateway::$apworldUploadResult = [
            'storageKey' => 'hash-tombola-04.apworld',
            'hash' => 'hash-tombola-04',
            'archipelagoGameName' => 'Tombola',
            'defaultYaml' => "name: Tombola\ngame: Tombola\n",
        ];
        $this->loginAs($this->admin);

        $path = tempnam(sys_get_temp_dir(), 'apw');
        self::assertIsString($path);
        file_put_contents($path, 'tombola 0.4');
        $this->client->request('PATCH', sprintf('/api/v1/admin/games/%s/apworld', $this->game->getId()), [], ['file' => new UploadedFile($path, 'tombola.apworld', 'application/octet-stream', null, true)]);

        self::assertResponseIsSuccessful();
        self::assertSame([true], NullRunnerGateway::$apworldUploadsWithPartner);
    }

    public function testTheVerdictSaysItHadAPartner(): void
    {
        NullRunnerGateway::$apworldPreflights = ['hash-tombola' => ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-10-31T10:00:00Z', 'overridden' => false, 'blocks' => false, 'withPartner' => true]];
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        $preflight = $this->gameData()['apworldPreflight'] ?? null;
        self::assertIsArray($preflight);
        self::assertTrue($preflight['withPartner'] ?? null);
    }

    private function changeTestPartner(mixed $withPartner): void
    {
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/apworld-test-partner', $this->game->getId()), ['withPartner' => $withPartner]);
    }

    /** @return array<mixed> */
    private function gameData(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
