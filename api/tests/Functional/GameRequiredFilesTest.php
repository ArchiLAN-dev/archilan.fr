<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;

/**
 * Story 44.3: the files a game reads from the launcher reach the screens before any generation, and an
 * admin can say a proven one is not needed after all.
 */
final class GameRequiredFilesTest extends FunctionalTestCase
{
    public function testTheRunOwnerSeesTheFilesOfTheRunsGamesOncePerSetting(): void
    {
        $owner = $this->createUser('owner@example.org');
        $guest = $this->createUser('guest@example.org');
        $game = $this->dkc3(proven: true);
        $run = $this->runWith($owner->getId(), $guest->getId(), $game);

        $this->loginAs($owner);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());

        self::assertResponseStatusCodeSame(200);
        $files = $this->data()['generationFiles'] ?? null;
        self::assertIsArray($files);
        self::assertCount(1, $files);
        self::assertIsArray($files[0]);
        self::assertSame('DK3Settings.rom_file', $files[0]['setting']);
        self::assertTrue($files[0]['neededAtGeneration']);
        self::assertSame(['120abf304f0c40fe059f6a192ed4f947'], $files[0]['md5s']);
        self::assertSame(['Donkey Kong Country 3'], $files[0]['games']);

        // A participant does not launch: nothing to provide.
        $this->loginAs($guest);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$run->getId());
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->data()['generationFiles'] ?? null);
    }

    public function testAnAdminClearsAProvenFile(): void
    {
        $game = $this->dkc3(proven: true);
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin'));

        $this->client->jsonRequest('GET', '/api/v1/admin/games/'.$game->getId());
        self::assertResponseStatusCodeSame(200);
        $files = $this->data()['requiredFiles'] ?? null;
        self::assertIsArray($files);
        self::assertIsArray($files[0]);
        self::assertTrue($files[0]['neededAtGeneration']);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/games/'.$game->getId().'/required-files/DK3Settings.rom_file/needed');
        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
        $fresh = $this->entityManager->find(Game::class, $game->getId());
        self::assertInstanceOf(Game::class, $fresh);
        self::assertFalse($fresh->getRequiredFiles()[0]['neededAtGeneration']);

        $this->client->jsonRequest('DELETE', '/api/v1/admin/games/'.$game->getId().'/required-files/DK3Settings.rom_file/needed');
        self::assertResponseStatusCodeSame(422);
    }

    public function testOnlyAnAdminClearsAFile(): void
    {
        $game = $this->dkc3(proven: true);
        $this->loginAs($this->createUser('player@example.org'));

        $this->client->jsonRequest('DELETE', '/api/v1/admin/games/'.$game->getId().'/required-files/DK3Settings.rom_file/needed');

        self::assertResponseStatusCodeSame(403);
    }

    private function dkc3(bool $proven): Game
    {
        $game = $this->createGame('Donkey Kong Country 3', 'dkc3');
        $game->recordDeclaredRequiredFiles([['setting' => 'DK3Settings.rom_file', 'file' => 'dkc3.sfc', 'extension' => '.sfc', 'description' => 'DKC3 (US) ROM File', 'md5s' => ['120abf304f0c40fe059f6a192ed4f947']]]);
        if ($proven) {
            $game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []);
        }
        $this->entityManager->flush();

        return $game;
    }

    private function runWith(string $ownerId, string $guestId, Game $game): Run
    {
        $now = new \DateTimeImmutable('2026-10-15T10:00:00+00:00');
        $run = Run::create($ownerId, 'Test Run', $now);
        $run->configureGames([['gameId' => $game->getId()]], $now);
        $this->entityManager->persist($run);
        foreach ([$ownerId, $guestId] as $userId) {
            $seat = RunParticipant::create($run->getId(), $userId, $now);
            // Two seats on the same game: the file is still asked once.
            $seat->replaceSlots([['slotId' => bin2hex(random_bytes(8)), 'gameId' => $game->getId()]]);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();

        return $run;
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
