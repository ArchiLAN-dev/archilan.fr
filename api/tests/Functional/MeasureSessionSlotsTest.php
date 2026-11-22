<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Application\Command\RecordSessionGeneratedOutput;
use App\Sessions\Application\Message\MeasureSessionSlotsJob;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Infrastructure\Double\StubSeedArchiveGateway;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 56.1: the size and progression share of each slot, recorded from the generation's multidata.
 *
 * Reading a multidata needs the orchestrator's Archipelago container: the gateway is simulated, and what is pinned
 * is the path around it - the multidata taken from the stored archive, the measures matched to the slots by name,
 * a failure that leaves the slots unmeasured, and the job queued once the output is recorded.
 */
final class MeasureSessionSlotsTest extends FunctionalTestCase
{
    private const string OUTPUT_KEY = 'session-56/output/archive.zip';

    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
        StubSeedArchiveGateway::reset();
    }

    protected function tearDown(): void
    {
        StubSeedArchiveGateway::reset();
        parent::tearDown();
    }

    public function testTheCommandRecordsTheMeasuresOfEachSlot(): void
    {
        $this->generatedSession();
        StubSeedArchiveGateway::willMeasure([
            ['name' => 'Alice', 'locations' => 120, 'ownProgression' => 30, 'hostedProgression' => 4],
            ['name' => 'Carol', 'locations' => 900, 'ownProgression' => 25, 'hostedProgression' => 51],
            // A slot of the multidata the session does not know is ignored.
            ['name' => 'Ghost', 'locations' => 1, 'ownProgression' => 1, 'hostedProgression' => 1],
        ]);

        $tester = $this->commandTester();
        self::assertSame(Command::SUCCESS, $tester->execute(['sessionId' => ['session-56']]));
        self::assertStringContainsString('session-56: measured.', $tester->getDisplay());

        self::assertSame(['AP_56.archipelago'], StubSeedArchiveGateway::measured());
        $alice = $this->slot('slot-alice');
        self::assertSame([120, 30, 4], [$alice->getLocationsTotal(), $alice->getOwnProgression(), $alice->getHostedProgression()]);
        $carol = $this->slot('slot-carol');
        self::assertSame([900, 25, 51], [$carol->getLocationsTotal(), $carol->getOwnProgression(), $carol->getHostedProgression()]);
        self::assertSame(51, $carol->payload()['hostedProgression']);
    }

    public function testAnUnreadableMultidataLeavesTheSlotsUnmeasured(): void
    {
        $this->generatedSession();
        StubSeedArchiveGateway::willMeasure(null);

        $tester = $this->commandTester();
        self::assertSame(Command::FAILURE, $tester->execute(['sessionId' => ['session-56']]));
        self::assertStringContainsString('session-56: unreadable.', $tester->getDisplay());

        self::assertNull($this->slot('slot-alice')->getLocationsTotal());
    }

    public function testASessionWithoutOutputIsNotMeasured(): void
    {
        $this->entityManager->persist(Session::create('session-empty', 'event-56', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $tester = $this->commandTester();
        self::assertSame(Command::FAILURE, $tester->execute(['sessionId' => ['session-empty', 'session-unknown']]));
        self::assertStringContainsString('session-empty: no_output.', $tester->getDisplay());
        self::assertStringContainsString('session-unknown: unknown_session.', $tester->getDisplay());
        self::assertSame([], StubSeedArchiveGateway::measured());
    }

    public function testRecordingTheOutputQueuesTheMeasure(): void
    {
        $this->entityManager->persist(Session::create('session-56', 'event-56', new \DateTimeImmutable()));
        $this->entityManager->flush();

        $record = self::getContainer()->get(RecordSessionGeneratedOutput::class);
        self::assertInstanceOf(RecordSessionGeneratedOutput::class, $record);
        $record->execute('session-56', self::OUTPUT_KEY);

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $jobs = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), iterator_to_array($transport->getSent(), false)),
            static fn (object $message): bool => $message instanceof MeasureSessionSlotsJob,
        ));
        self::assertCount(1, $jobs);
        self::assertInstanceOf(MeasureSessionSlotsJob::class, $jobs[0]);
        self::assertSame('session-56', $jobs[0]->sessionId);
    }

    private function generatedSession(): void
    {
        $session = Session::create('session-56', 'event-56', new \DateTimeImmutable());
        $session->markGenerated(self::OUTPUT_KEY);
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create('slot-alice', 'session-56', 'reg-a', 'game-a', 'Alice', 0));
        $this->entityManager->persist(SessionSlot::create('slot-carol', 'session-56', 'reg-c', 'game-c', 'Carol', 1));
        $this->entityManager->flush();

        $zipPath = tempnam(sys_get_temp_dir(), 'ap56');
        self::assertIsString($zipPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::OVERWRITE));
        $zip->addFromString('AP_56.archipelago', "\x03multidata");
        $zip->addFromString('AP_56_Spoiler.txt', 'spoiler');
        $zip->close();
        $contents = file_get_contents($zipPath);
        unlink($zipPath);
        self::assertIsString($contents);

        $storage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $storage);
        $storage->upload('sessions', self::OUTPUT_KEY, $contents);
    }

    private function slot(string $id): SessionSlot
    {
        $this->entityManager->clear();
        $slot = $this->entityManager->find(SessionSlot::class, $id);
        self::assertInstanceOf(SessionSlot::class, $slot);

        return $slot;
    }

    private function commandTester(): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find('app:sessions:measure-slots'));
    }
}
