<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use Doctrine\ORM\Tools\SchemaTool;

final class PersonalRunTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Run::class),
            $this->entityManager->getClassMetadata(RunParticipant::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testUnauthenticatedRequestsReturn401(): void
    {
        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'My Run']);
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('GET', '/api/v1/runs/mine');
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('GET', '/api/v1/runs/doesnotexist');
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('DELETE', '/api/v1/runs/doesnotexist');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateRunReturns201WithDraftPayload(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'Ma partie perso']);

        self::assertResponseStatusCodeSame(201);
        $data = $this->responseData();
        self::assertSame('Ma partie perso', $data['title']);
        self::assertSame(Run::STATUS_DRAFT, $data['status']);
        self::assertSame($user->getId(), $data['ownerId']);
        self::assertIsString($data['inviteToken']);
        self::assertSame(64, strlen($data['inviteToken']));
        self::assertIsString($data['id']);
        self::assertSame(32, strlen($data['id']));
        self::assertSame([], $data['participants']);
        self::assertTrue($data['isOwner']);
    }

    public function testCreateRunValidatesTitle(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => '']);
        self::assertResponseStatusCodeSame(422);
        $details = $this->errorDetails();
        self::assertArrayHasKey('title', $details);

        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => str_repeat('a', 81)]);
        self::assertResponseStatusCodeSame(422);
        $details = $this->errorDetails();
        self::assertArrayHasKey('title', $details);

        // Exactly 80 chars is accepted
        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => str_repeat('a', 80)]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testListMineReturnsOnlyOwnedRuns(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'Alice Run 1']);
        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'Alice Run 2']);

        $this->loginAs($bob);
        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'Bob Run']);

        $this->loginAs($alice);
        $this->client->jsonRequest('GET', '/api/v1/runs/mine');
        self::assertResponseIsSuccessful();

        $data = $this->responseDataList();
        self::assertCount(2, $data);
        $titles = array_column($data, 'title');
        self::assertContains('Alice Run 1', $titles);
        self::assertContains('Alice Run 2', $titles);
        self::assertNotContains('Bob Run', $titles);
    }

    public function testListMineReturnsEmptyArrayWhenNoRuns(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/runs/mine');
        self::assertResponseIsSuccessful();
        $data = $this->responseDataList();
        self::assertSame([], $data);
    }

    public function testGetRunAsOwnerReturns200(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'My Run']);
        $runId = $this->createdRunId();

        $this->client->jsonRequest('GET', '/api/v1/runs/'.$runId);
        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame($runId, $data['id']);
        self::assertSame('My Run', $data['title']);
        self::assertIsArray($data['participants']);
        self::assertTrue($data['isOwner']);
    }

    public function testGetRunAsStrangerReturns403(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');

        $this->loginAs($alice);
        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'Alice Run']);
        $runId = $this->createdRunId();

        $this->loginAs($bob);
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$runId);
        self::assertResponseStatusCodeSame(403);
    }

    public function testGetNonExistentRunReturns404(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('GET', '/api/v1/runs/'.bin2hex(random_bytes(16)));
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteDraftRunReturns204(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $this->client->jsonRequest('POST', '/api/v1/runs', ['title' => 'To Delete']);
        $runId = $this->createdRunId();

        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$runId);
        self::assertResponseStatusCodeSame(204);

        // Run is now cancelled
        $this->client->jsonRequest('GET', '/api/v1/runs/'.$runId);
        self::assertResponseIsSuccessful();
        $data = $this->responseData();
        self::assertSame(Run::STATUS_CANCELLED, $data['status']);
    }

    public function testDeleteIdleRunReturns204(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $run = $this->createRunDirectly($user->getId(), 'Idle Run', Run::STATUS_IDLE);

        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());
        self::assertResponseStatusCodeSame(204);
    }

    public function testDeleteActiveRunReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $run = $this->createRunDirectly($user->getId(), 'Active Run', Run::STATUS_ACTIVE);

        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_active', $this->errorCode());
    }

    public function testDeleteStartingRunReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $run = $this->createRunDirectly($user->getId(), 'Starting Run', Run::STATUS_STARTING);

        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_active', $this->errorCode());
    }

    public function testDeleteCompletedRunReturns422(): void
    {
        $user = $this->createUser('alice@example.org');
        $this->loginAs($user);

        $run = $this->createRunDirectly($user->getId(), 'Completed Run', Run::STATUS_COMPLETED);

        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSame('run_not_deletable', $this->errorCode());
    }

    public function testDeleteRunAsNonOwnerReturns403(): void
    {
        $alice = $this->createUser('alice@example.org');
        $bob = $this->createUser('bob@example.org');

        $run = $this->createRunDirectly($alice->getId(), 'Alice Run', Run::STATUS_DRAFT);

        $this->loginAs($bob);
        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$run->getId());
        self::assertResponseStatusCodeSame(403);
    }

    private function createRunDirectly(string $ownerId, string $title, string $status): Run
    {
        $now = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $run = Run::create($ownerId, $title, $now);

        $reflection = new \ReflectionProperty(Run::class, 'status');
        $reflection->setValue($run, $status);

        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(): array
    {
        $decoded = $this->decodedResponse();
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        $result = [];
        foreach ($data as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    private function createdRunId(): string
    {
        $id = $this->responseData()['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<mixed>
     */
    private function responseDataList(): array
    {
        $decoded = $this->decodedResponse();
        $data = $decoded['data'] ?? null;
        self::assertIsArray($data);

        return array_values($data);
    }

    private function errorCode(): string
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    private function errorDetails(): array
    {
        $decoded = $this->decodedResponse();
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);

        $result = [];
        foreach ($details as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedResponse(): array
    {
        $content = $this->client->getResponse()->getContent() ?: '';
        $decoded = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }
}
