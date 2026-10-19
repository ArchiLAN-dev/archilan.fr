<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\CatalogSync\Application\Handler\CheckApworldUpdatesHandler;
use App\CatalogSync\Application\Message\CheckApworldUpdatesMessage;
use App\GameSelection\Domain\Entity\Game;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 38.18: « Vérifier les mises à jour » queues a pass for the worker instead of checking in the request,
 * which PHP cut at 30 seconds.
 */
final class AdminCatalogSyncTest extends FunctionalTestCase
{
    private const string URI = '/api/v1/admin/catalog-sync/check-updates';

    public function testCheckUpdatesRequiresAdmin(): void
    {
        $this->client->jsonRequest('POST', self::URI);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('user@example.org', ['ROLE_USER']));

        $this->client->jsonRequest('POST', self::URI);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', self::URI);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheClickQueuesACheckAndChecksNothingInTheRequest(): void
    {
        $game = $this->trackedGame();
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', self::URI);

        self::assertResponseStatusCodeSame(202);
        $data = $this->data();
        self::assertSame('queued', $data['status'] ?? null);
        self::assertSame('button', $data['trigger'] ?? null);
        self::assertEquals([new CheckApworldUpdatesMessage(is_string($data['id'] ?? null) ? $data['id'] : '', submit: false)], $this->queuedChecks());

        $this->entityManager->refresh($game);
        self::assertNull($game->getApworldCheckedAt(), 'nothing is checked in the request');
    }

    public function testASecondClickDuringAPassIsRefused(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->jsonRequest('POST', self::URI);
        self::assertResponseStatusCodeSame(202);

        $this->client->jsonRequest('POST', self::URI);

        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('already_running', $error['code'] ?? null);
    }

    public function testThePageFindsThePassAgainAndFollowsItToItsReport(): void
    {
        $game = $this->trackedGame();
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->request('GET', self::URI);
        self::assertResponseIsSuccessful();
        $body = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $body);
        self::assertNull($body['data'], 'no pass yet');

        $this->client->jsonRequest('POST', self::URI);
        $runId = $this->data()['id'] ?? null;
        self::assertIsString($runId);

        // The worker's turn.
        $this->githubAnswersARelease();
        $handler = self::getContainer()->get(CheckApworldUpdatesHandler::class);
        self::assertInstanceOf(CheckApworldUpdatesHandler::class, $handler);
        $handler(new CheckApworldUpdatesMessage($runId, submit: false));

        $this->client->request('GET', self::URI);
        self::assertResponseIsSuccessful();
        $data = $this->data();
        self::assertSame($runId, $data['id'] ?? null);
        self::assertSame('done', $data['status'] ?? null);
        self::assertSame(1, $data['checked'] ?? null);
        self::assertSame(1, $data['total'] ?? null);
        self::assertIsString($data['finishedAt'] ?? null);

        $this->entityManager->clear();
        $saved = $this->entityManager->find(Game::class, $game->getId());
        self::assertSame('1.2.0', $saved?->getApworldLatestVersion());
        self::assertNotNull($saved->getApworldCheckedAt());
    }

    private function trackedGame(): Game
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->updateCatalogueMetadata(sourceUrl: 'https://github.com/nicholasb/hollow-knight');
        $this->entityManager->flush();

        return $game;
    }

    /** Set on the container the handler runs in: the kernel is rebooted between requests. */
    private function githubAnswersARelease(): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse(
                (string) json_encode([[
                    'tag_name' => 'v1.2.0',
                    'published_at' => '2026-01-01T00:00:00Z',
                    'html_url' => 'https://github.com/nicholasb/hollow-knight/releases/tag/v1.2.0',
                    'assets' => [
                        ['name' => 'hollow-knight.apworld', 'browser_download_url' => 'https://example.com/hk.apworld'],
                    ],
                    'draft' => false,
                    'prerelease' => false,
                ]]),
                ['response_headers' => ['x-ratelimit-remaining' => ['50']]],
            ),
        ]);
    }

    /**
     * @return list<CheckApworldUpdatesMessage>
     */
    private function queuedChecks(): array
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $messages = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof CheckApworldUpdatesMessage) {
                $messages[] = $message;
            }
        }

        return $messages;
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
