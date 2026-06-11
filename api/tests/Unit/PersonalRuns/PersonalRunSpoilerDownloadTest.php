<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\PersonalRunSpoilerDownload;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Application\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Application\SpoilerArtifact;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PersonalRunSpoilerDownloadTest extends TestCase
{
    private const RUN_ID = 'run-0000000000000000000000000001';
    private const SESSION_ID = 'sess-000000000000000000000000001';
    private const OWNER_ID = 'owner-00000000000000000000000001';
    private const OTHER_ID = 'other-00000000000000000000000001';

    public function testReturnsNotFoundWhenRunMissing(): void
    {
        $reader = $this->createMock(SessionSpoilerArtifactReaderInterface::class);
        $reader->expects(self::never())->method('extractSpoiler');

        $result = $this->service(null, null, $reader)->execute(self::RUN_ID, self::OWNER_ID, false);

        self::assertFalse($result['found']);
        self::assertFalse($result['authorized']);
        self::assertNull($result['spoiler']);
    }

    public function testReturnsForbiddenWhenNotOwnerAndNotAdmin(): void
    {
        $reader = $this->createMock(SessionSpoilerArtifactReaderInterface::class);
        $reader->expects(self::never())->method('extractSpoiler');

        $result = $this->service($this->launchedRun(), $this->session(null), $reader)
            ->execute(self::RUN_ID, self::OTHER_ID, false);

        self::assertTrue($result['found']);
        self::assertFalse($result['authorized']);
        self::assertNull($result['spoiler']);
    }

    public function testOwnerGetsSpoilerUsingPersistedKey(): void
    {
        $artifact = new SpoilerArtifact('AP_seed_Spoiler.txt', 'spoiler-bytes');
        $reader = $this->createMock(SessionSpoilerArtifactReaderInterface::class);
        $reader->expects(self::once())->method('extractSpoiler')->with('custom/key.zip')->willReturn($artifact);

        $result = $this->service($this->launchedRun(), $this->session('custom/key.zip'), $reader)
            ->execute(self::RUN_ID, self::OWNER_ID, false);

        self::assertTrue($result['authorized']);
        self::assertSame($artifact, $result['spoiler']);
    }

    public function testAdminGetsSpoilerEvenIfNotOwner(): void
    {
        $artifact = new SpoilerArtifact('AP_seed_Spoiler.txt', 'x');
        $reader = $this->createMock(SessionSpoilerArtifactReaderInterface::class);
        $reader->expects(self::once())->method('extractSpoiler')->willReturn($artifact);

        $result = $this->service($this->launchedRun(), $this->session('k.zip'), $reader)
            ->execute(self::RUN_ID, self::OTHER_ID, true);

        self::assertTrue($result['authorized']);
        self::assertSame($artifact, $result['spoiler']);
    }

    public function testFallsBackToDeterministicKeyWhenSessionKeyAbsent(): void
    {
        $reader = $this->createMock(SessionSpoilerArtifactReaderInterface::class);
        $reader->expects(self::once())
            ->method('extractSpoiler')
            ->with(self::SESSION_ID.'/output/archive.zip')
            ->willReturn(null);

        $result = $this->service($this->launchedRun(), $this->session(null), $reader)
            ->execute(self::RUN_ID, self::OWNER_ID, false);

        self::assertTrue($result['authorized']);
        self::assertNull($result['spoiler']);
    }

    public function testReturnsNullSpoilerWhenRunNotLaunched(): void
    {
        $run = Run::create(self::OWNER_ID, 'My run', new \DateTimeImmutable()); // no sessionId
        $reader = $this->createMock(SessionSpoilerArtifactReaderInterface::class);
        $reader->expects(self::never())->method('extractSpoiler');

        $result = $this->service($run, null, $reader)->execute(self::RUN_ID, self::OWNER_ID, false);

        self::assertTrue($result['authorized']);
        self::assertArrayHasKey('spoiler', $result);
        self::assertNull($result['spoiler']);
    }

    private function service(?Run $run, ?Session $session, SessionSpoilerArtifactReaderInterface $reader): PersonalRunSpoilerDownload
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $sessions = $this->createStub(SessionRepositoryInterface::class);
        $sessions->method('findById')->willReturn($session);

        return new PersonalRunSpoilerDownload($runs, $sessions, $reader);
    }

    private function launchedRun(): Run
    {
        $run = Run::create(self::OWNER_ID, 'My run', new \DateTimeImmutable());
        $run->setSessionId(self::SESSION_ID);

        return $run;
    }

    private function session(?string $generatedOutputKey): Session
    {
        $session = Session::create(self::SESSION_ID, self::RUN_ID, new \DateTimeImmutable());
        if (null !== $generatedOutputKey) {
            $session->setGeneratedOutputKey($generatedOutputKey);
        }

        return $session;
    }
}
