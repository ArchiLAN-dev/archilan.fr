<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Domain\Session;

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

    private function createSession(string $id): Session
    {
        $session = Session::create($id, 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }
}
