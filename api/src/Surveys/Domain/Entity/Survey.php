<?php

declare(strict_types=1);

namespace App\Surveys\Domain\Entity;

use App\Surveys\Domain\Enum\SurveyAudience;
use App\Surveys\Domain\Enum\SurveyStatus;
use Doctrine\ORM\Mapping as ORM;

/**
 * A survey on an event (story 51.1): questions the organisers ask a public, over a period. A draft changes freely
 * and is deleted at will; once published, its audience and its anonymity are fixed and it is never deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'survey')]
#[ORM\Index(name: 'idx_survey_event', columns: ['event_id'])]
final class Survey
{
    public const int MAX_TITLE = 120;
    public const int MAX_INTRO = 1000;

    /** Story 51.3: the automatic reminder goes out this long before the close... */
    public const string REMINDER_LEAD = '-48 hours';
    /** ...or halfway through a period shorter than this many seconds (4 days). */
    public const int SHORT_PERIOD = 345600;
    /** Two reminders are at least this far apart, the automatic one included. */
    public const string REMIND_INTERVAL = '+24 hours';

    private function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(type: 'string', length: 120)]
        private string $title,
        #[ORM\Column(type: 'text')]
        private string $intro,
        #[ORM\Column(type: 'string', length: 16, enumType: SurveyAudience::class)]
        private SurveyAudience $audience,
        #[ORM\Column(type: 'boolean')]
        private bool $anonymous,
        #[ORM\Column(name: 'send_email', type: 'boolean')]
        private bool $sendEmail,
        #[ORM\Column(name: 'opens_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $opensAt,
        #[ORM\Column(name: 'closes_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $closesAt,
        #[ORM\Column(type: 'string', length: 16, enumType: SurveyStatus::class)]
        private SurveyStatus $status,
        #[ORM\Column(name: 'published_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $publishedAt,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'opening_notified_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $openingNotifiedAt = null,
        #[ORM\Column(name: 'reminder_sent_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $reminderSentAt = null,
        #[ORM\Column(name: 'last_reminded_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastRemindedAt = null,
    ) {
    }

    /** @throws \DomainException survey_title_invalid, survey_intro_invalid, survey_period_invalid */
    public static function draft(
        string $id,
        string $eventId,
        string $title,
        string $intro,
        SurveyAudience $audience,
        bool $anonymous,
        bool $sendEmail,
        \DateTimeImmutable $opensAt,
        \DateTimeImmutable $closesAt,
        \DateTimeImmutable $now,
    ): self {
        self::assertPeriod($opensAt, $closesAt);

        return new self(
            $id,
            $eventId,
            self::validTitle($title),
            self::validIntro($intro),
            $audience,
            $anonymous,
            $sendEmail,
            $opensAt,
            $closesAt,
            SurveyStatus::Draft,
            null,
            $now,
            $now,
        );
    }

    /**
     * Each null field is left as it is. A published survey keeps its audience and its anonymity: asking to change
     * either is refused, asking for the value it already has is not a change.
     *
     * @throws \DomainException survey_title_invalid, survey_intro_invalid, survey_period_invalid, survey_locked_field
     */
    public function revise(
        ?string $title,
        ?string $intro,
        ?SurveyAudience $audience,
        ?bool $anonymous,
        ?bool $sendEmail,
        ?\DateTimeImmutable $opensAt,
        ?\DateTimeImmutable $closesAt,
        \DateTimeImmutable $now,
    ): void {
        $locked = SurveyStatus::Published === $this->status;
        if ($locked && ((null !== $audience && $audience !== $this->audience) || (null !== $anonymous && $anonymous !== $this->anonymous))) {
            throw new \DomainException('survey_locked_field');
        }
        $opensAt ??= $this->opensAt;
        $closesAt ??= $this->closesAt;
        self::assertPeriod($opensAt, $closesAt);

        $this->title = null === $title ? $this->title : self::validTitle($title);
        $this->intro = null === $intro ? $this->intro : self::validIntro($intro);
        $this->audience = $audience ?? $this->audience;
        $this->anonymous = $anonymous ?? $this->anonymous;
        $this->sendEmail = $sendEmail ?? $this->sendEmail;
        $this->opensAt = $opensAt;
        $this->closesAt = $closesAt;
        $this->updatedAt = $now;
    }

    /**
     * A survey goes out with at least one question, and each choice question with at least two options still
     * offered.
     *
     * @param list<SurveyQuestion> $questions
     * @param array<string, int>   $activeOptionCounts per question id, the options not retired
     *
     * @throws \DomainException survey_already_published, survey_questions_missing, survey_question_options_missing
     */
    public function publish(array $questions, array $activeOptionCounts, \DateTimeImmutable $now): void
    {
        if (SurveyStatus::Published === $this->status) {
            throw new \DomainException('survey_already_published');
        }
        if ([] === $questions) {
            throw new \DomainException('survey_questions_missing');
        }
        foreach ($questions as $question) {
            if ($question->getKind()->hasOptions() && ($activeOptionCounts[$question->getId()] ?? 0) < SurveyQuestion::MIN_OPTIONS) {
                throw new \DomainException('survey_question_options_missing');
            }
        }
        $this->status = SurveyStatus::Published;
        $this->publishedAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws \DomainException survey_published */
    public function assertDeletable(): void
    {
        if (SurveyStatus::Published === $this->status) {
            throw new \DomainException('survey_published');
        }
    }

    public function isPublished(): bool
    {
        return SurveyStatus::Published === $this->status;
    }

    /** Published, and within its period: open from its opening, closed from its closing (story 51.2). */
    public function isOpenAt(\DateTimeImmutable $now): bool
    {
        return $this->isPublished() && $this->opensAt <= $now && $now < $this->closesAt;
    }

    /** Story 51.3: open, and its audience not told yet. Told once, even when the period moves afterwards. */
    public function isDueForOpeningNotice(\DateTimeImmutable $now): bool
    {
        return $this->isOpenAt($now) && null === $this->openingNotifiedAt;
    }

    /** Told this late, the opening notice is the reminder: the automatic one would follow it within minutes. */
    public function markOpeningNotified(\DateTimeImmutable $now): void
    {
        $this->openingNotifiedAt ??= $now;
        if ($now >= $this->reminderDueAt()) {
            $this->reminderSentAt ??= $now;
        }
    }

    /** 48 hours before the close, or halfway through a period shorter than four days. */
    public function reminderDueAt(): \DateTimeImmutable
    {
        $span = $this->closesAt->getTimestamp() - $this->opensAt->getTimestamp();
        if ($span < self::SHORT_PERIOD) {
            return $this->opensAt->modify(sprintf('+%d seconds', intdiv($span, 2)));
        }

        return $this->closesAt->modify(self::REMINDER_LEAD);
    }

    /** The automatic reminder is due once, after the opening notice, while the survey is open. */
    public function isDueForReminder(\DateTimeImmutable $now): bool
    {
        return $this->isOpenAt($now)
            && null !== $this->openingNotifiedAt
            && null === $this->reminderSentAt
            && $now >= $this->reminderDueAt();
    }

    /** A reminder went out less than 24 hours ago, by hand or automatically. */
    public function wasRemindedRecently(\DateTimeImmutable $now): bool
    {
        return null !== $this->lastRemindedAt && $now < $this->lastRemindedAt->modify(self::REMIND_INTERVAL);
    }

    /**
     * The automatic reminder is done, whether it went out or was skipped after a recent manual one: it never comes
     * back.
     */
    public function markAutomaticReminder(\DateTimeImmutable $now, bool $sent): void
    {
        $this->reminderSentAt ??= $now;
        if ($sent) {
            $this->lastRemindedAt = $now;
        }
    }

    /** @throws \DomainException survey_closed, survey_remind_too_soon */
    public function remindByHand(\DateTimeImmutable $now): void
    {
        if (!$this->isOpenAt($now)) {
            throw new \DomainException('survey_closed');
        }
        if ($this->wasRemindedRecently($now)) {
            throw new \DomainException('survey_remind_too_soon');
        }
        $this->lastRemindedAt = $now;
        // Whoever is reminded by hand has been told it opened.
        $this->openingNotifiedAt ??= $now;
    }

    /** A change to a question or an option is a change to the survey. */
    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getIntro(): string
    {
        return $this->intro;
    }

    public function getAudience(): SurveyAudience
    {
        return $this->audience;
    }

    public function isAnonymous(): bool
    {
        return $this->anonymous;
    }

    public function sendsEmail(): bool
    {
        return $this->sendEmail;
    }

    public function getOpensAt(): \DateTimeImmutable
    {
        return $this->opensAt;
    }

    public function getClosesAt(): \DateTimeImmutable
    {
        return $this->closesAt;
    }

    public function getStatus(): SurveyStatus
    {
        return $this->status;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getOpeningNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->openingNotifiedAt;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function getLastRemindedAt(): ?\DateTimeImmutable
    {
        return $this->lastRemindedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function assertPeriod(\DateTimeImmutable $opensAt, \DateTimeImmutable $closesAt): void
    {
        if ($closesAt <= $opensAt) {
            throw new \DomainException('survey_period_invalid');
        }
    }

    private static function validTitle(string $title): string
    {
        $title = trim($title);
        if ('' === $title || mb_strlen($title) > self::MAX_TITLE) {
            throw new \DomainException('survey_title_invalid');
        }

        return $title;
    }

    private static function validIntro(string $intro): string
    {
        $intro = trim($intro);
        if (mb_strlen($intro) > self::MAX_INTRO) {
            throw new \DomainException('survey_intro_invalid');
        }

        return $intro;
    }
}
