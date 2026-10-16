<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\Identity\Domain\Entity\User;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 44.6: an admin re-runs the test generation of a game's apworld with the file its world reads (a ROM).
 * The file is handed to the orchestrator during the request, only its one-use id goes on; nothing is stored.
 */
final class AdminGameTestWithRequiredFileTest extends FunctionalTestCase
{
    private User $admin;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();

        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean', slug: 'jean');
        $this->game = $this->createGame('Donkey Kong Country 3', 'dkc3');
        $this->game->configureApworld('hash-dkc3.apworld', 'hash-dkc3', 'Donkey Kong Country 3', "dkc3\n", new \DateTimeImmutable('2026-10-01'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testTheTestRunsWithTheProvidedFileAndOnlyItsIdTravels(): void
    {
        $this->loginAs($this->admin);

        $this->rerun(['DK3Settings.rom_file' => $this->upload('rom bytes', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([['setting' => 'DK3Settings.rom_file', 'filename' => 'dkc3.sfc', 'content' => 'rom bytes']], NullRunnerGateway::$stagedFiles);
        self::assertSame([['hash' => 'hash-dkc3', 'requiredFileIds' => ['staged-1']]], NullRunnerGateway::$apworldPreflightRuns);
    }

    public function testATestWithoutFileRunsAsBefore(): void
    {
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-preflight', $this->game->getId()));

        self::assertResponseStatusCodeSame(202);
        self::assertSame([['hash' => 'hash-dkc3', 'requiredFileIds' => []]], NullRunnerGateway::$apworldPreflightRuns);
        self::assertSame([], NullRunnerGateway::$stagedFiles);
    }

    public function testAnUnknownSettingIsRefusedBeforeAnythingIsSent(): void
    {
        $this->loginAs($this->admin);

        $this->rerun(['not a setting' => $this->upload('rom', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->errorCode());
        self::assertSame([], NullRunnerGateway::$stagedFiles);
        self::assertSame([], NullRunnerGateway::$apworldPreflightRuns);
    }

    public function testARefusedFileLeavesTheVerdictAlone(): void
    {
        NullRunnerGateway::$refuseStagingOf = 'DK3Settings.rom_file';
        $this->loginAs($this->admin);

        $this->rerun(['DK3Settings.rom_file' => $this->upload('rom', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('required_file_refused', $this->errorCode());
        self::assertSame([], NullRunnerGateway::$apworldPreflightRuns);
    }

    public function testARunnerThatDoesNotAnswerReleasesTheHeldFile(): void
    {
        NullRunnerGateway::$apworldPreflightUnavailable = true;
        $this->loginAs($this->admin);

        $this->rerun(['DK3Settings.rom_file' => $this->upload('rom', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['staged-1'], NullRunnerGateway::$releasedFileIds);
    }

    public function testTheVerdictSaysItPassedWithTheFile(): void
    {
        NullRunnerGateway::$apworldPreflights['hash-dkc3'] = ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-10-16T10:00:00+00:00', 'overridden' => false, 'blocks' => false, 'withRequiredFiles' => true];
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $preflight = $data['apworldPreflight'] ?? null;
        self::assertIsArray($preflight);
        self::assertTrue($preflight['withRequiredFiles'] ?? null);
    }

    // Story 38.16: a failed verdict comes with its one-line summary, the raw tail kept for the log.
    public function testAFailedVerdictComesWithItsSummary(): void
    {
        $raw = 'generate_multiworld.py exited 1: Traceback (most recent call last):
  File "x.py", line 1
Fill.FillError: Could not access required locations for accessibility check.';
        NullRunnerGateway::$apworldPreflights['hash-dkc3'] = ['status' => 'failed', 'error' => $raw, 'checkedAt' => '2026-10-16T10:00:00+00:00', 'overridden' => false, 'blocks' => true];
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $preflight = $data['apworldPreflight'] ?? null;
        self::assertIsArray($preflight);
        self::assertSame('Fill.FillError: Could not access required locations for accessibility check.', $preflight['summary'] ?? null);
        self::assertSame($raw, $preflight['error'] ?? null);
    }

    // Review of epic 44: editing the template of a ROM game does not rerun its test without the ROM.
    public function testEditingTheTemplateOfARomGameAsksForATestWithTheFile(): void
    {
        $this->game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', 'DKC3 (US) ROM File', []);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/games/%s/default-yaml', $this->game->getId()), ['defaultYaml' => "name: Player{number}\ngame: Donkey Kong Country 3\n"]);

        self::assertResponseIsSuccessful();
        self::assertSame([], NullRunnerGateway::$apworldPreflightRuns);
        $meta = $this->decodedJsonResponse()['meta'] ?? null;
        self::assertIsArray($meta);
        $warning = $meta['warning'] ?? null;
        self::assertIsString($warning);
        self::assertStringContainsString('relance le test avec lui', $warning);
    }

    /** @param array<string, UploadedFile> $files */
    private function rerun(array $files): void
    {
        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-preflight', $this->game->getId()), [], ['requiredFiles' => $files]);
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rom');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    private function errorCode(): string
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }
}
