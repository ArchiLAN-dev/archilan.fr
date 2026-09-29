<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Port\WebPushOutcome;
use App\Community\Infrastructure\Adapter\MinishlinkWebPushSender;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\MessageSentReport;
use PHPUnit\Framework\TestCase;

/**
 * Story 40.2. What the push service's answer means for the device: delivered, gone (404 / 410), or a
 * failure to log. Encryption and VAPID signing are the library's (RFC 8291 / 8292); they need an EC key
 * per send, which PHP for Windows cannot create without a system OPENSSL_CONF, so they are not run here.
 */
final class MinishlinkWebPushSenderTest extends TestCase
{
    public function testAnAcceptedPushIsDelivered(): void
    {
        self::assertSame(WebPushOutcome::Delivered, MinishlinkWebPushSender::outcomeOf($this->report(201, true)));
    }

    public function testAGoneDeviceIsExpired(): void
    {
        self::assertSame(WebPushOutcome::Expired, MinishlinkWebPushSender::outcomeOf($this->report(410, false)));
        self::assertSame(WebPushOutcome::Expired, MinishlinkWebPushSender::outcomeOf($this->report(404, false)));
    }

    public function testAnyOtherAnswerIsAFailure(): void
    {
        self::assertSame(WebPushOutcome::Failed, MinishlinkWebPushSender::outcomeOf($this->report(403, false)));
        self::assertSame(WebPushOutcome::Failed, MinishlinkWebPushSender::outcomeOf($this->report(500, false)));
        self::assertSame(
            WebPushOutcome::Failed,
            MinishlinkWebPushSender::outcomeOf(new MessageSentReport(new Request('POST', 'https://push.example/device-1'), null, false, 'timeout')),
        );
    }

    private function report(int $status, bool $success): MessageSentReport
    {
        return new MessageSentReport(new Request('POST', 'https://push.example/device-1'), new Response($status), $success);
    }
}
