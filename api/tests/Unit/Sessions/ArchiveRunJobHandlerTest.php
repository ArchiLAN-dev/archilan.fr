<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Handler\ArchiveRunJobHandler;
use App\Sessions\Application\Message\ArchiveRunJob;
use App\Sessions\Application\Port\RunnerCallbackClientInterface;
use App\Sessions\Application\Port\SessionSaveStorageInterface;
use App\Sessions\Infrastructure\Adapter\MinioSessionSaveStorage;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Story 17.37: the archive records the save the orchestrateur stored, and nothing when there is none. */
final class ArchiveRunJobHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
    }

    public function testRecordsTheStoredSave(): void
    {
        new NullMinioStorage()->upload('sessions', 's1/save/latest.apsave', 'progress');

        self::assertSame('s1/save/latest.apsave', $this->archive('s1')['archived_save_path']);
    }

    public function testRecordsNoSaveWhenNoneWasStored(): void
    {
        self::assertNull($this->archive('s2')['archived_save_path']);
    }

    public function testSaveStorageReadsAndLocatesTheSameObject(): void
    {
        $storage = new MinioSessionSaveStorage(new NullMinioStorage(), 'sessions');
        self::assertNull($storage->read('s3'));
        new NullMinioStorage()->upload('sessions', MinioSessionSaveStorage::key('s3'), 'progress');
        self::assertSame('progress', $storage->read('s3'));
        self::assertSame('s3/save/latest.apsave', $storage->locate('s3'));
    }

    /** @return array<string, mixed> the callback payload */
    private function archive(string $sessionId): array
    {
        $callback = new class implements RunnerCallbackClientInterface {
            /** @var array<string, mixed> */
            public array $payload = [];

            public function sendCallback(string $sessionId, array $payload): void
            {
                $this->payload = $payload;
            }
        };
        $saves = new MinioSessionSaveStorage(new NullMinioStorage(), 'sessions');
        self::assertInstanceOf(SessionSaveStorageInterface::class, $saves);

        (new ArchiveRunJobHandler($callback, new NullLogger(), self::createStub(HttpClientInterface::class), $saves, 'runner-test'))(new ArchiveRunJob($sessionId));

        return $callback->payload;
    }
}
