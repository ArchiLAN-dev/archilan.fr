<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\GameCatalogSync;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AdminGameTutorialTest extends FunctionalTestCase
{
    private const EMPTY_CSV = "Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\n";

    public function testAdminSavesTutorialSteps(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/tutorial', $game->getId()), [
            'steps' => [
                ['type' => 'apworld', 'title' => "Installer l'apworld", 'description' => 'desc', 'links' => [
                    ['label' => 'Releases', 'url' => 'https://example.org/r'],
                ]],
                ['type' => 'connect', 'title' => 'Se connecter', 'description' => '', 'links' => []],
            ],
        ]);

        self::assertResponseStatusCodeSame(200);
        $steps = $this->installStepsFromResponse();
        self::assertCount(2, $steps);

        $first = $steps[0];
        self::assertIsArray($first);
        self::assertSame('apworld', $first['type']);
        self::assertSame("Installer l'apworld", $first['title']);
        self::assertIsArray($first['links']);
        $link = $first['links'][0];
        self::assertIsArray($link);
        self::assertSame('https://example.org/r', $link['url']);

        $second = $steps[1];
        self::assertIsArray($second);
        self::assertSame('connect', $second['type']);
    }

    public function testInvalidStepTypeIsRejected(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/tutorial', $game->getId()), [
            'steps' => [['type' => 'bogus', 'title' => 'x', 'links' => []]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testBlankTitleIsRejected(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/tutorial', $game->getId()), [
            'steps' => [['type' => 'note', 'title' => '   ', 'links' => []]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testNonHttpLinkUrlIsRejected(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/tutorial', $game->getId()), [
            'steps' => [['type' => 'note', 'title' => 'x', 'links' => [['label' => 'evil', 'url' => 'javascript:alert(1)']]]],
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    public function testSeedBundledGameYieldsIncludedNote(): void
    {
        $this->loginAsAdmin();

        $game = $this->createGame('Clique', 'clique');
        $sync = new GameCatalogSync($game, bundledWithAp: true);
        $this->entityManager->persist($sync);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tutorial/seed', $game->getId()));

        self::assertResponseStatusCodeSame(200);
        $steps = $this->installStepsFromResponse();
        $first = $steps[0];
        self::assertIsArray($first);
        self::assertSame('note', $first['type']);
        self::assertSame('Rien à installer', $first['title']);
    }

    public function testSeedApworldGameFoldsSheetLinks(): void
    {
        $this->loginAsAdmin();

        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->updateCatalogueMetadata(catalogSheetName: 'Hollow Knight', sourceUrl: 'https://github.com/owner/hk');
        $this->entityManager->flush();

        $httpClient = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $httpClient);
        $httpClient->setResponseFactory([
            new MockResponse("Name,Stability,PR Status,Links & Downloads,18+ / Unrated,Notes\nHollow Knight,Stable,,Guide d'installation,No,\n"),
            new MockResponse(self::EMPTY_CSV),
        ]);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tutorial/seed', $game->getId()));

        self::assertResponseStatusCodeSame(200);
        $steps = $this->installStepsFromResponse();
        $apworld = $steps[0];
        self::assertIsArray($apworld);
        self::assertSame('apworld', $apworld['type']);
        self::assertIsArray($apworld['links']);

        $labels = [];
        foreach ($apworld['links'] as $link) {
            self::assertIsArray($link);
            self::assertIsString($link['label']);
            $labels[] = $link['label'];
        }
        self::assertContains("Source de l'apworld", $labels);
        self::assertContains("Guide d'installation", $labels);
    }

    public function testSeedDoesNotOverwriteWithoutForce(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/tutorial', $game->getId()), [
            'steps' => [['type' => 'note', 'title' => 'Mon étape', 'links' => []]],
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tutorial/seed', $game->getId()));
        self::assertResponseStatusCodeSame(200);

        $steps = $this->installStepsFromResponse();
        self::assertCount(1, $steps);
        $first = $steps[0];
        self::assertIsArray($first);
        self::assertSame('Mon étape', $first['title']);
    }

    public function testTutorialEndpointsRequireAdmin(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/tutorial', $game->getId()), ['steps' => []]);
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('user@example.org', ['ROLE_USER']);
        $this->loginAs($user);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/tutorial/seed', $game->getId()));
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function installStepsFromResponse(): array
    {
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $steps = $data['installSteps'];
        self::assertIsArray($steps);

        return $steps;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
