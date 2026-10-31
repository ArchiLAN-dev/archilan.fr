<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notes;

use App\Notes\Domain\Entity\AdminNote;
use App\Notes\Domain\Enum\FollowUpKind;
use App\Notes\Domain\Enum\NoteStatus;
use App\Notes\Domain\Enum\NoteTargetType;
use PHPUnit\Framework\TestCase;

/** Story 46.1: an admin note, its follow-ups and its revisions. */
final class AdminNoteTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-10-30T12:00:00+01:00');
    }

    public function testANewNoteIsOpenAndTrimmed(): void
    {
        $note = $this->note('  A payé en espèces.  ');

        self::assertSame('A payé en espèces.', $note->getBody());
        self::assertSame(NoteStatus::Open, $note->getStatus());
        self::assertSame(NoteTargetType::Registration, $note->getTargetType());
        self::assertSame('author', $note->getAuthorId());
    }

    public function testTheBodyBoundsAreEnforced(): void
    {
        self::assertSame(2000, mb_strlen($this->note(str_repeat('é', 2000))->getBody()));

        foreach (['   ', str_repeat('é', 2001)] as $body) {
            try {
                $this->note($body);
                self::fail('A body of '.mb_strlen($body).' characters must be refused.');
            } catch (\DomainException $e) {
                self::assertSame('note_body_invalid', $e->getMessage());
            }
        }
    }

    public function testAnEditKeepsTheAuthorAndReturnsThePreviousText(): void
    {
        $note = $this->note('Arrive à 14 h.');
        $later = $this->now->modify('+1 hour');

        $revision = $note->edit('r1', 'Arrive à 16 h.', 'editor', $later);

        self::assertNotNull($revision);
        self::assertSame('Arrive à 14 h.', $revision->getPreviousBody());
        self::assertSame('editor', $revision->getEditorId());
        self::assertSame($note->getId(), $revision->getNoteId());
        self::assertSame('Arrive à 16 h.', $note->getBody());
        self::assertSame('author', $note->getAuthorId());
        self::assertEquals($later, $note->getUpdatedAt());
    }

    public function testAnUnchangedTextIsNoEdit(): void
    {
        $note = $this->note('Arrive à 14 h.');

        self::assertNull($note->edit('r1', '  Arrive à 14 h. ', 'editor', $this->now->modify('+1 hour')));
        self::assertEquals($this->now, $note->getUpdatedAt());
    }

    public function testSettlingAndReopeningAreFollowUpsWithAnOptionalText(): void
    {
        $note = $this->note('Autorisation parentale attendue.');

        $settled = $note->settle('f1', '', 'author', $this->now);
        self::assertSame(NoteStatus::Settled, $note->getStatus());
        self::assertSame(FollowUpKind::Settled, $settled->getKind());
        self::assertSame('', $settled->getBody());

        $reopened = $note->reopen('f2', 'Le mail était pour un autre enfant.', 'editor', $this->now);
        self::assertSame(NoteStatus::Open, $note->getStatus());
        self::assertSame(FollowUpKind::Reopened, $reopened->getKind());
        self::assertSame('editor', $reopened->getAuthorId());
    }

    public function testAStateAlreadyReachedIsRefused(): void
    {
        $note = $this->note('Doublon possible.');
        try {
            $note->reopen('f1', '', 'author', $this->now);
            self::fail('An open note cannot be reopened.');
        } catch (\DomainException $e) {
            self::assertSame('note_already_open', $e->getMessage());
        }

        $note->settle('f2', '', 'author', $this->now);
        $this->expectExceptionObject(new \DomainException('note_already_settled'));
        $note->settle('f3', '', 'author', $this->now);
    }

    public function testACommentNeedsATextAndASettledNoteStillTakesOne(): void
    {
        $note = $this->note('Place à garder.');
        $note->settle('f1', '', 'author', $this->now);

        $comment = $note->followUp('f2', 'Finalement il vient seul.', 'editor', $this->now);
        self::assertSame(FollowUpKind::Comment, $comment->getKind());

        $this->expectExceptionObject(new \DomainException('note_follow_up_body_invalid'));
        $note->followUp('f3', '  ', 'editor', $this->now);
    }

    public function testAFollowUpIsBounded(): void
    {
        $this->expectExceptionObject(new \DomainException('note_follow_up_body_invalid'));

        $this->note('Note.')->settle('f1', str_repeat('a', 2001), 'author', $this->now);
    }

    private function note(string $body): AdminNote
    {
        return AdminNote::write('n1', NoteTargetType::Registration, 'reg1', $body, 'author', $this->now);
    }
}
