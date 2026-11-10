<?php

declare(strict_types=1);

namespace App\Surveys\Application\Service;

use App\Communications\Application\Message\SurveyNoticeMessage;
use App\Community\Application\Support\Notifier;
use App\Surveys\Application\Port\SurveyEventInterface;
use App\Surveys\Application\Query\SurveyAudienceQueryInterface;
use App\Surveys\Domain\Entity\Survey;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 51.3: tells the members of a survey's audience who have not answered it that it opened, or reminds them.
 * Called after the survey was saved: every send is best effort, and a failing one never undoes the others. The bell
 * (and the devices, by the member's preferences) always; the e-mail when the survey asks for it.
 */
final readonly class SurveyAnnouncer
{
    public const string OPENED = 'survey_opened';
    public const string REMINDER = 'survey_reminder';

    public function __construct(
        private SurveyAudienceQueryInterface $audience,
        private SurveyEventInterface $events,
        private Notifier $notifier,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private string $siteUrl,
    ) {
    }

    /** How many members were told. */
    public function announce(Survey $survey, bool $reminder): int
    {
        $recipients = $this->audience->unanswered($survey->getAudience(), $survey->getEventId(), $survey->getId());
        $event = $this->events->period($survey->getEventId());
        $eventTitle = null === $event ? '' : $event->title;
        $answerUrl = sprintf('%s/evenements/%s/sondages/%s', rtrim($this->siteUrl, '/'), $survey->getEventId(), $survey->getId());
        $closesAt = $survey->getClosesAt()->setTimezone(new \DateTimeZone('Europe/Paris'))->format('d/m/Y \à H\hi');
        $payload = [
            'surveyId' => $survey->getId(),
            'surveyTitle' => $survey->getTitle(),
            'eventId' => $survey->getEventId(),
            'eventTitle' => $eventTitle,
            'closesAt' => $survey->getClosesAt()->format(\DateTimeInterface::ATOM),
            'anonymous' => $survey->isAnonymous(),
        ];

        foreach ($recipients as $recipient) {
            $this->notifier->notify($recipient['userId'], $reminder ? self::REMINDER : self::OPENED, $payload);
            if (!$survey->sendsEmail() || '' === $recipient['email']) {
                continue;
            }
            try {
                $this->messageBus->dispatch(new SurveyNoticeMessage(
                    $recipient['email'],
                    $recipient['displayName'],
                    $survey->getTitle(),
                    $eventTitle,
                    $closesAt,
                    $survey->isAnonymous(),
                    $answerUrl,
                    $reminder,
                ));
            } catch (\Throwable $e) {
                $this->logger->warning('survey.notice_mail_failed', ['surveyId' => $survey->getId(), 'userId' => $recipient['userId'], 'error' => $e->getMessage()]);
            }
        }

        $this->logger->info($reminder ? 'survey.reminded' : 'survey.opening_notified', ['surveyId' => $survey->getId(), 'count' => count($recipients)]);

        return count($recipients);
    }
}
