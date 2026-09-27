<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\ModerationCaseMessage;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.2: a member's message to the moderation, kept in their case.
 */
final class ModerationCaseMessageTest extends TestCase
{
    public function testAMemberMessageIsTrimmedAndSignedByTheMember(): void
    {
        $message = ModerationCaseMessage::fromMember('case-1', 'user-1', "  OK pour le ban, mais j'aimerais être remboursé  \n", new \DateTimeImmutable('2026-09-27 10:00:00'));

        self::assertSame('case-1', $message->getCaseId());
        self::assertSame('user-1', $message->getAuthorUserId());
        self::assertSame(ModerationCaseMessage::AUTHOR_MEMBER, $message->getAuthorRole());
        self::assertSame(ModerationCaseMessage::SOURCE_SITE, $message->getSource());
        self::assertSame("OK pour le ban, mais j'aimerais être remboursé", $message->getBody());
        self::assertSame(32, strlen($message->getId()));
    }

    public function testAnEmptyOrOversizedMessageIsRefused(): void
    {
        foreach (['   ', str_repeat('a', ModerationCaseMessage::MAX_LENGTH + 1)] as $body) {
            try {
                ModerationCaseMessage::fromMember('case-1', 'user-1', $body, new \DateTimeImmutable());
                self::fail('refused: '.mb_substr($body, 0, 10));
            } catch (\InvalidArgumentException) {
            }
        }

        $longest = ModerationCaseMessage::fromMember('case-1', 'user-1', str_repeat('é', ModerationCaseMessage::MAX_LENGTH), new \DateTimeImmutable());
        self::assertSame(ModerationCaseMessage::MAX_LENGTH, mb_strlen($longest->getBody()), 'counted in characters, not bytes');
    }

    public function testAStaffReplyIsSignedByTheModeratorAndAwaitsItsDirectMessage(): void
    {
        $reply = ModerationCaseMessage::fromStaff('case-1', 'admin-1', ' Le remboursement est en cours. ', new \DateTimeImmutable('2026-09-27 11:00:00'));

        self::assertSame(ModerationCaseMessage::AUTHOR_STAFF, $reply->getAuthorRole());
        self::assertSame('admin-1', $reply->getAuthorUserId());
        self::assertSame('Le remboursement est en cours.', $reply->getBody());
        self::assertNull($reply->getDiscordDmStatus(), 'not delivered yet');
    }

    public function testTheDirectMessageOutcomeIsRecordedOnce(): void
    {
        $reply = ModerationCaseMessage::fromStaff('case-1', 'admin-1', 'Bonjour', new \DateTimeImmutable());

        $reply->recordDirectMessage(ModerationCaseMessage::DM_SENT);
        $reply->recordDirectMessage(ModerationCaseMessage::DM_FAILED);

        self::assertSame(ModerationCaseMessage::DM_SENT, $reply->getDiscordDmStatus());
    }

    public function testAStaffReplyFollowsTheSameLengthRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ModerationCaseMessage::fromStaff('case-1', 'admin-1', '  ', new \DateTimeImmutable());
    }
}
