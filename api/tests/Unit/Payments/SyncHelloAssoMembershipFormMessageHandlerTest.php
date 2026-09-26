<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Payments\Application\Handler\SyncHelloAssoMembershipFormMessageHandler;
use App\Payments\Application\Message\SyncHelloAssoFormMessage;
use App\Payments\Application\Message\SyncHelloAssoMembershipFormMessage;
use App\Payments\Application\Support\HelloAssoConfig;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Story 22.7: the hourly backstop that catches a membership payment the webhook missed - the same sync
 * as the admin "Synchroniser HelloAsso" button, without the click.
 */
final class SyncHelloAssoMembershipFormMessageHandlerTest extends TestCase
{
    public function testTheMembershipFormIsSynced(): void
    {
        $bus = new RecordingMessageBus();

        new SyncHelloAssoMembershipFormMessageHandler($bus, new RecordingLogger(), 'adhesion-2026')(new SyncHelloAssoMembershipFormMessage());

        $syncs = $bus->messagesOf(SyncHelloAssoFormMessage::class);
        self::assertCount(1, $syncs);
        self::assertSame(HelloAssoConfig::FORM_TYPE_MEMBERSHIP, $syncs[0]->formType);
        self::assertSame('adhesion-2026', $syncs[0]->formSlug);
    }

    public function testNoConfiguredFormMeansNoSync(): void
    {
        $bus = new RecordingMessageBus();
        $logger = new RecordingLogger();

        new SyncHelloAssoMembershipFormMessageHandler($bus, $logger, '')(new SyncHelloAssoMembershipFormMessage());

        self::assertSame([], $bus->messages);
        self::assertContains(['level' => 'info', 'message' => 'helloasso.membership_sync_skipped_not_configured'], $logger->logs);
    }
}
