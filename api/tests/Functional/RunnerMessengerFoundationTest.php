<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;

final class RunnerMessengerFoundationTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    // ─── Runner callback endpoint ─────────────────────────────────────────────

    public function testRunnerCallbackRequiresInternalSecret(): void
    {
        $session = $this->createSession();

        // No header → 401
        $this->client->jsonRequest(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $session->getId()),
            ['status' => 'logs'],
        );
        self::assertResponseStatusCodeSame(401);

        // Wrong secret → 401
        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $session->getId()),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'wrong', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'logs', 'output' => ''], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testRunnerCallbackWithUnknownStatusReturnsOk(): void
    {
        $session = $this->createSession();

        $this->sendCallback($session->getId(), ['status' => 'some-unknown-event']);

        self::assertResponseIsSuccessful();
        $data = $this->jsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame(true, $data['ok']);
    }

    public function testRunnerCallbackLogsStatusReturns404ForUnknownSession(): void
    {
        $this->sendCallback('no-such-session', ['status' => 'logs', 'output' => '']);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRunnerCallbackIncludesExtraFieldsGracefully(): void
    {
        $session = $this->createSession();

        $this->sendCallback($session->getId(), [
            'status' => 'some-event',
            'extra_field' => 'runner-prod-01',
        ]);

        self::assertResponseIsSuccessful();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function createSession(): Session
    {
        $session = Session::create(bin2hex(random_bytes(8)), 'evt-001', new \DateTimeImmutable());
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    /** @param array<string, mixed> $payload */
    private function sendCallback(string $sessionId, array $payload): void
    {
        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $sessionId),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<mixed> */
    private function jsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
