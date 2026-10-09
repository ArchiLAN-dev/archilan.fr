<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Support\PushMessageFactory;
use PHPUnit\Framework\TestCase;

/**
 * Story 40.2. Only an explicit list of notification types goes out as a browser push, with the
 * text of the bell and a link into the site.
 */
final class PushMessageFactoryTest extends TestCase
{
    public function testOnlyListedTypesArePushed(): void
    {
        self::assertTrue(PushMessageFactory::isPushable('slot_unblocked'));
        self::assertFalse(PushMessageFactory::isPushable('friend_request_received'));
        self::assertNull(PushMessageFactory::forNotification('friend_request_received', []));
    }

    public function testAnUnblockedSlotSaysWhereAndLeadsToTheRun(): void
    {
        $message = PushMessageFactory::forNotification('slot_unblocked', [
            'runId' => 'run-1',
            'runTitle' => 'Ma run',
            'slotName' => 'Alice_HK1',
            'reachableNow' => 3,
        ]);

        self::assertNotNull($message);
        self::assertSame('ArchiLAN', $message->title);
        self::assertSame('Tu n\'es plus bloqué dans « Ma run » (Alice_HK1) : 3 checks accessibles', $message->body);
        self::assertSame('/runs/run-1', $message->url);
        self::assertSame('slot_unblocked-run-1-Alice_HK1', $message->tag);
        self::assertSame(
            ['title' => 'ArchiLAN', 'body' => $message->body, 'url' => '/runs/run-1', 'tag' => 'slot_unblocked-run-1-Alice_HK1'],
            json_decode($message->toJson(), true),
        );
    }

    public function testAnUnblockedSlotWithItsNumberLeadsToItsProgression(): void
    {
        // Story 40.5.
        $message = PushMessageFactory::forNotification('slot_unblocked', ['runId' => 'run-1', 'runTitle' => 'Ma run', 'slotName' => 'Alice_HK1', 'reachableNow' => 3, 'slotIndex' => '4']);

        self::assertSame('/runs/run-1/progression/4', $message?->url);
    }

    public function testARunInvitationSaysWhoAndLeadsToMyRuns(): void
    {
        // Story 43.1.
        self::assertTrue(PushMessageFactory::isPushable('run_invitation'));
        $message = PushMessageFactory::forNotification('run_invitation', ['inviterName' => 'Alice', 'runTitle' => 'Ma run', 'invitationId' => 'inv-1']);

        self::assertNotNull($message);
        self::assertSame('Alice t\'invite dans « Ma run »', $message->body);
        self::assertSame('/compte/parties', $message->url);
        self::assertSame('run_invitation-inv-1', $message->tag);
        self::assertSame('Un ami t\'invite dans sa partie', PushMessageFactory::forNotification('run_invitation', [])?->body);
    }

    public function testASingleCheckAndABarePayloadStayReadable(): void
    {
        $single = PushMessageFactory::forNotification('slot_unblocked', ['runId' => 'run-1', 'runTitle' => 'Ma run', 'slotName' => 'Alice_HK1', 'reachableNow' => 1]);
        self::assertSame('Tu n\'es plus bloqué dans « Ma run » (Alice_HK1) : 1 check accessible', $single?->body);

        $bare = PushMessageFactory::forNotification('slot_unblocked', []);
        self::assertNotNull($bare);
        self::assertSame('Tu n\'es plus bloqué dans ta partie', $bare->body);
        self::assertSame('/compte/parties', $bare->url);
    }
}
