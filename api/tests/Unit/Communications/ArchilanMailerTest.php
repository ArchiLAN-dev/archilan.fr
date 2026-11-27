<?php

declare(strict_types=1);

namespace App\Tests\Unit\Communications;

use App\Communications\Application\Email\ArchilanEmail;
use App\Communications\Application\Service\ArchilanMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * #1229: the SMTP relay refused a mail once with "421 Service not available" and the member never got it. A 4xx
 * reply is retried twice, a few seconds apart; a 5xx or any other failure is final.
 */
final class ArchilanMailerTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-27T10:00:00+00:00');
    }

    public function testATransientRefusalIsRetriedUntilTheMailLeaves(): void
    {
        $mailer = new ScriptedMailer([self::smtp(421), null]);

        self::assertTrue($this->archilanMailer($mailer)->send(new PlainTestEmail()));
        self::assertSame(2, $mailer->attempts);
        self::assertEquals(new \DateTimeImmutable('2026-11-27T10:00:02+00:00'), $this->clock->now());
    }

    public function testATransientRefusalThatLastsGivesUpAfterTwoRetries(): void
    {
        $mailer = new ScriptedMailer([self::smtp(421), self::smtp(421), self::smtp(421), null]);

        self::assertFalse($this->archilanMailer($mailer)->send(new PlainTestEmail()));
        self::assertSame(3, $mailer->attempts);
        self::assertEquals(new \DateTimeImmutable('2026-11-27T10:00:07+00:00'), $this->clock->now());
    }

    public function testAFinalRefusalIsNotRetried(): void
    {
        $mailer = new ScriptedMailer([self::smtp(550), null]);

        self::assertFalse($this->archilanMailer($mailer)->send(new PlainTestEmail()));
        self::assertSame(1, $mailer->attempts);
    }

    public function testAnotherFailureIsNotRetried(): void
    {
        $mailer = new ScriptedMailer([new \RuntimeException('boom'), null]);

        self::assertFalse($this->archilanMailer($mailer)->send(new PlainTestEmail()));
        self::assertSame(1, $mailer->attempts);
    }

    private function archilanMailer(MailerInterface $mailer): ArchilanMailer
    {
        return new ArchilanMailer($mailer, new NullLogger(), 'noreply@archilan.fr', $this->clock);
    }

    private static function smtp(int $code): UnexpectedResponseException
    {
        return new UnexpectedResponseException(sprintf('Expected response code "250" but got code "%d".', $code), $code);
    }
}

final class ScriptedMailer implements MailerInterface
{
    public int $attempts = 0;

    /**
     * @param list<\Throwable|null> $outcomes one per attempt: the failure to throw, or null for a sent mail
     */
    public function __construct(private array $outcomes)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        $outcome = $this->outcomes[$this->attempts] ?? null;
        ++$this->attempts;
        if (null !== $outcome) {
            throw $outcome;
        }
    }
}

final class PlainTestEmail extends ArchilanEmail
{
    public function to(): string
    {
        return 'alice@example.org';
    }

    public function toName(): string
    {
        return 'Alice';
    }

    public function subject(): string
    {
        return 'Test';
    }

    public function textBody(): string
    {
        return 'Bonjour';
    }
}
