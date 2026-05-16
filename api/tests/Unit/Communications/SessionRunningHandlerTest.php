<?php

declare(strict_types=1);

namespace App\Tests\Unit\Communications;

use App\Communications\Application\ArchilanMailer;
use App\Communications\Application\SessionRunningHandler;
use App\Communications\Application\SessionRunningMessage;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Mime\Email;

final class SessionRunningHandlerTest extends TestCase
{
    private MailerInterface&Stub $innerMailer;
    private HubInterface&Stub $hub;

    protected function setUp(): void
    {
        $this->innerMailer = $this->createStub(MailerInterface::class);
        $this->hub = $this->createStub(HubInterface::class);
        $this->hub->method('publish')->willReturn('');
    }

    public function testEmailIsSentToRegistrant(): void
    {
        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send');
        $handler = $this->makeHandler(innerMailer: $innerMailer);

        $handler($this->makeMessage());
    }

    public function testEmailSubjectContainsEventTitle(): void
    {
        $sentEmail = null;
        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $this->makeHandler(innerMailer: $innerMailer)($this->makeMessage(eventTitle: 'ArchiLAN 2026'));

        self::assertNotNull($sentEmail);
        self::assertStringContainsString('ArchiLAN 2026', $sentEmail->getSubject() ?? '');
    }

    public function testEmailBodyContainsSlotNames(): void
    {
        $sentEmail = null;
        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $this->makeHandler(innerMailer: $innerMailer)($this->makeMessage(slotNames: ['Jean_HK1', 'Jean_ALTTP1']));

        self::assertNotNull($sentEmail);
        $bodyRaw = $sentEmail->getTextBody();
        self::assertIsString($bodyRaw);
        self::assertStringContainsString('Jean_HK1', $bodyRaw);
        self::assertStringContainsString('Jean_ALTTP1', $bodyRaw);
    }

    public function testEmailBodyContainsConnectionInfo(): void
    {
        $sentEmail = null;
        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $this->makeHandler(innerMailer: $innerMailer)($this->makeMessage(host: '10.0.0.1', port: 9042, password: 'secret'));

        self::assertNotNull($sentEmail);
        $bodyRaw = $sentEmail->getTextBody();
        self::assertIsString($bodyRaw);
        self::assertStringContainsString('10.0.0.1', $bodyRaw);
        self::assertStringContainsString('9042', $bodyRaw);
        self::assertStringContainsString('secret', $bodyRaw);
    }

    public function testEmailAddressedToRegistrant(): void
    {
        $sentEmail = null;
        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $this->makeHandler(innerMailer: $innerMailer)(
            $this->makeMessage(userEmail: 'player@example.org', userDisplayName: 'Jean'),
        );

        self::assertNotNull($sentEmail);
        $to = $sentEmail->getTo();
        self::assertCount(1, $to);
        self::assertSame('player@example.org', $to[0]->getAddress());
        self::assertSame('Jean', $to[0]->getName());
    }

    public function testMercureIsPublishedOnPlayerPrivateTopic(): void
    {
        $publishedUpdate = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$publishedUpdate): string {
                $publishedUpdate = $update;

                return '';
            });

        $this->makeHandler(hub: $hub)($this->makeMessage(userId: 'user-abc'));

        self::assertNotNull($publishedUpdate);
        self::assertSame('/users/user-abc/session-alerts', $publishedUpdate->getTopics()[0]);
        self::assertTrue($publishedUpdate->isPrivate());
    }

    public function testMercurePayloadContainsSessionInfo(): void
    {
        $publishedUpdate = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$publishedUpdate): string {
                $publishedUpdate = $update;

                return '';
            });

        $this->makeHandler(hub: $hub)($this->makeMessage(
            sessionId: 'sess-xyz',
            host: '10.0.0.2',
            port: 8765,
            password: 'pw123',
            slotNames: ['Bob_HK1'],
        ));

        self::assertNotNull($publishedUpdate);
        $payload = json_decode($publishedUpdate->getData(), true);
        self::assertIsArray($payload);
        self::assertSame('session.running', $payload['type']);
        self::assertSame('sess-xyz', $payload['sessionId']);
        self::assertSame('10.0.0.2', $payload['host']);
        self::assertSame(8765, $payload['port']);
        self::assertSame('pw123', $payload['password']);
        self::assertSame(['Bob_HK1'], $payload['slotNames']);
    }

    public function testMercureFailureDoesNotPreventEmailSend(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('Mercure down'));

        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send');

        $this->makeHandler(innerMailer: $innerMailer, hub: $hub)($this->makeMessage());
    }

    public function testEmailTransportExceptionIsHandledGracefully(): void
    {
        $innerMailer = $this->createStub(MailerInterface::class);
        $innerMailer->method('send')->willThrowException(new \RuntimeException('Transport down'));

        $this->makeHandler(innerMailer: $innerMailer)($this->makeMessage());
        // No exception propagates — ArchilanMailer absorbs it and logs the failure.
        $this->addToAssertionCount(1);
    }

    public function testEmailFallsBackToEmailAsNameWhenNoDisplayName(): void
    {
        $sentEmail = null;
        $innerMailer = $this->createMock(MailerInterface::class);
        $innerMailer->expects($this->once())->method('send')
            ->willReturnCallback(static function (Email $email) use (&$sentEmail): void {
                $sentEmail = $email;
            });

        $this->makeHandler(innerMailer: $innerMailer)(
            $this->makeMessage(userEmail: 'anon@example.org', userDisplayName: null),
        );

        self::assertNotNull($sentEmail);
        $bodyRaw = $sentEmail->getTextBody();
        self::assertIsString($bodyRaw);
        self::assertStringContainsString('anon@example.org', $bodyRaw);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function makeHandler(
        ?MailerInterface $innerMailer = null,
        ?HubInterface $hub = null,
    ): SessionRunningHandler {
        $mailer = new ArchilanMailer(
            $innerMailer ?? $this->innerMailer,
            new NullLogger(),
            'noreply@archilan.fr',
        );

        return new SessionRunningHandler(
            $mailer,
            $hub ?? $this->hub,
            new NullLogger(),
        );
    }

    /**
     * @param list<string> $slotNames
     */
    private function makeMessage(
        string $sessionId = 'sess-1',
        string $registrationId = 'reg-1',
        string $userId = 'user-1',
        string $userEmail = 'player@example.org',
        ?string $userDisplayName = 'Jean',
        string $eventTitle = 'ArchiLAN 2026',
        string $host = '10.0.0.1',
        int $port = 9042,
        string $password = 'secret',
        array $slotNames = ['Jean_HK1'],
    ): SessionRunningMessage {
        return new SessionRunningMessage(
            sessionId: $sessionId,
            registrationId: $registrationId,
            userId: $userId,
            userEmail: $userEmail,
            userDisplayName: $userDisplayName,
            eventTitle: $eventTitle,
            host: $host,
            port: $port,
            password: $password,
            slotNames: $slotNames,
        );
    }
}
