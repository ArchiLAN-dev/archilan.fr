<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Entity\Session;
use Symfony\Component\HttpClient\MockHttpClient;

final class PublicOverlaySubscribeTest extends FunctionalTestCase
{
    public function testSubscribeReturns404ForUnknownSession(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/public/overlay/nope/subscribe');
        self::assertResponseStatusCodeSame(404);
    }

    public function testSubscribeIsTokenlessAndGrantsReadOnlyTopics(): void
    {
        $this->createSession('run-overlay-1');

        // No token, no auth: overlays are read-only and meant to be shown on stream.
        $this->client->jsonRequest('GET', '/api/v1/public/overlay/run-overlay-1/subscribe');
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertIsString($data['token']);
        self::assertSame(
            [
                'runs/run-overlay-1/feed',
                'runs/run-overlay-1/players',
                'runs/run-overlay-1/slots/{slot}/reachable',
                'runs/run-overlay-1/overlay-test',
            ],
            $data['topics'],
        );
    }

    public function testTheReachableOverlayOfAnUnknownSessionIs404(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/public/overlay/nope/slots/2/reachable');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheReachableOverlayIsEmptyUntilAPushAndStartsNothing(): void
    {
        $this->createSession('run-overlay-empty');
        $bridge = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $bridge);

        $this->client->jsonRequest('GET', '/api/v1/public/overlay/run-overlay-empty/slots/2/reachable');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => null], $this->decodedJsonResponse());
        self::assertSame(0, $bridge->getRequestsCount());
    }

    public function testTheReachableOverlayShowsTheLastPushWithoutItemsNorSpheres(): void
    {
        $this->createSession('run-overlay-pushed');
        // A pool that lives as long as the container: the two requests must share it.
        $this->client->disableReboot();

        $this->client->jsonRequest(
            'POST',
            '/api/v1/internal/sessions/run-overlay-pushed/slots/2/reachable-push',
            [
                'counts' => ['reachable_now' => 1],
                'reachable_unchecked' => [['id' => 1, 'name' => 'Cave', 'item' => ['name' => 'Hookshot']]],
                'spheres' => [['index' => 0, 'locations' => [['id' => 1, 'name' => 'Cave']]]],
            ],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret'],
        );
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('GET', '/api/v1/public/overlay/run-overlay-pushed/slots/2/reachable');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['data' => ['counts' => ['reachable_now' => 1], 'reachable_unchecked' => [['id' => 1, 'name' => 'Cave']]]],
            $this->decodedJsonResponse(),
        );
    }

    private function createSession(string $id): Session
    {
        $session = Session::create($id, 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }
}
