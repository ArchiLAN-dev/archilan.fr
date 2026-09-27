<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCaseMessage;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.4: a sanction records how its direct message reached the member, once.
 */
final class ModerationActionDirectMessageTest extends TestCase
{
    public function testTheFirstOutcomeStands(): void
    {
        $action = ModerationAction::create('admin-1', 'user-1', ModerationAction::ACTION_BAN, 'Triche', new \DateTimeImmutable());
        self::assertNull($action->getDiscordDmStatus());

        $action->recordDirectMessage(ModerationCaseMessage::DM_SENT);
        $action->recordDirectMessage(ModerationCaseMessage::DM_FAILED);

        self::assertSame(ModerationCaseMessage::DM_SENT, $action->getDiscordDmStatus());
    }
}
