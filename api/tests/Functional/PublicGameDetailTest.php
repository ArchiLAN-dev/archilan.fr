<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PublicGameDetailTest extends FunctionalTestCase
{
    private const EMPTY_CSV = "Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\n";

    public function testReturnsDetailPayloadWithSheetMetadataAndOptions(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->updateCatalogueMetadata(catalogSheetName: 'Hollow Knight', sourceUrl: 'https://github.com/owner/hollow-knight');
        $game->setOptionTypes(['goal' => ['min' => 0, 'max' => 3, 'default' => 1]]);
        $this->entityManager->flush();

        $this->configureSheetMock(
            "Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\nHollow Knight,Stable,,Github Releases,No,Tested by the team\n",
        );

        $this->client->jsonRequest('GET', '/api/v1/games/hollow-knight');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('hollow-knight', $data['slug']);
        self::assertSame('Hollow Knight', $data['name']);
        self::assertSame('available', $data['availability']);
        self::assertFalse($data['bundledWithAp']);
        self::assertFalse($data['adultContent']);

        self::assertIsArray($data['options']);
        self::assertSame([['key' => 'goal', 'min' => 0, 'max' => 3, 'default' => 1]], $data['options']);

        self::assertIsArray($data['apworld']);
        self::assertSame('https://github.com/owner/hollow-knight', $data['apworld']['sourceUrl']);
        self::assertSame(Game::UPDATE_STATUS_UNKNOWN, $data['apworld']['updateStatus']);

        self::assertIsArray($data['catalog']);
        self::assertSame('Tested by the team', $data['catalog']['notes']);
        self::assertSame([['label' => 'Github Releases', 'url' => null]], $data['catalog']['links']);

        // Match-only hints must not leak into the public payload.
        self::assertArrayNotHasKey('catalogSheetName', $data);
        self::assertArrayNotHasKey('archipelagoGameName', $data);
    }

    public function testReturnsEmptyMetadataWhenNoSheetMatch(): void
    {
        $this->createGame('Standalone Game', 'standalone-game');
        $this->entityManager->flush();

        $this->configureSheetMock(self::EMPTY_CSV);

        $this->client->jsonRequest('GET', '/api/v1/games/standalone-game');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['catalog']);
        self::assertNull($data['catalog']['notes']);
        self::assertSame([], $data['catalog']['links']);
        self::assertSame([], $data['options']);
    }

    public function testExposesInstallSteps(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->setInstallSteps([
            ['type' => 'apworld', 'title' => "Installer l'apworld", 'description' => 'desc', 'links' => [
                ['label' => 'Releases', 'url' => 'https://example.org/r'],
            ]],
        ]);
        $this->entityManager->flush();

        $this->configureSheetMock(self::EMPTY_CSV);

        $this->client->jsonRequest('GET', '/api/v1/games/hollow-knight');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['installSteps']);
        self::assertCount(1, $data['installSteps']);
        $step = $data['installSteps'][0];
        self::assertIsArray($step);
        self::assertSame('apworld', $step['type']);
        self::assertIsArray($step['links']);
        $link = $step['links'][0];
        self::assertIsArray($link);
        self::assertSame('https://example.org/r', $link['url']);
    }

    public function testExposesStepImageAndVideo(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->setInstallSteps([
            ['type' => 'note', 'title' => 'Avec média', 'description' => '', 'links' => [], 'imageUrl' => 'https://example.org/shot.png', 'videoUrl' => 'https://youtu.be/abcdefghijk'],
        ]);
        $this->entityManager->flush();

        $this->configureSheetMock(self::EMPTY_CSV);

        $this->client->jsonRequest('GET', '/api/v1/games/hollow-knight');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['installSteps']);
        $step = $data['installSteps'][0];
        self::assertIsArray($step);
        self::assertSame('https://example.org/shot.png', $step['imageUrl']);
        self::assertSame('https://youtu.be/abcdefghijk', $step['videoUrl']);
    }

    public function testStepImageKeyIsPresignedAtRead(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->setInstallSteps([
            ['type' => 'note', 'title' => 'Image uploadée', 'description' => '', 'links' => [], 'imageKey' => 'tutorials/abc123.png'],
        ]);
        $this->entityManager->flush();

        $this->configureSheetMock(self::EMPTY_CSV);

        $this->client->jsonRequest('GET', '/api/v1/games/hollow-knight');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['installSteps']);
        $step = $data['installSteps'][0];
        self::assertIsArray($step);
        // The uploaded key wins and is resolved to a presigned URL that embeds the object key.
        self::assertSame('tutorials/abc123.png', $step['imageKey']);
        self::assertIsString($step['imageUrl']);
        self::assertStringContainsString('tutorials/abc123.png', $step['imageUrl']);
    }

    public function testInstallStepsWithUnknownTypeAreDropped(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->setInstallSteps([
            ['type' => 'bogus', 'title' => 'Mauvaise étape', 'description' => '', 'links' => []],
            ['type' => 'connect', 'title' => 'Se connecter', 'description' => '', 'links' => []],
        ]);
        $this->entityManager->flush();

        $this->configureSheetMock(self::EMPTY_CSV);

        $this->client->jsonRequest('GET', '/api/v1/games/hollow-knight');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsArray($data['installSteps']);
        self::assertCount(1, $data['installSteps']);
        $step = $data['installSteps'][0];
        self::assertIsArray($step);
        self::assertSame('connect', $step['type']);
    }

    public function testReturns404ForUnknownSlug(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/games/does-not-exist');
        self::assertResponseStatusCodeSame(404);

        $response = $this->decodedJsonResponse();
        self::assertSame('Game not found', $response['error']);
    }

    public function testReturns404ForUnavailableGame(): void
    {
        $this->createGame('Hidden Game', 'hidden-game', Game::AVAILABILITY_UNAVAILABLE);
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/games/hidden-game');
        self::assertResponseStatusCodeSame(404);
    }

    private function configureSheetMock(string $mainCsv): void
    {
        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse($mainCsv),
            new MockResponse(self::EMPTY_CSV),
        ]);
    }
}
