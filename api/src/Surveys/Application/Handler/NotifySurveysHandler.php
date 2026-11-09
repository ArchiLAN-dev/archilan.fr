<?php

declare(strict_types=1);

namespace App\Surveys\Application\Handler;

use App\Surveys\Application\Message\NotifySurveysMessage;
use App\Surveys\Application\Service\SurveyAnnouncer;
use App\Surveys\Domain\Repository\SurveyRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Story 51.3. Each open survey is announced once, then reminded once to those who have not answered. The mark is saved
 * before anything goes out, so a pass that runs again, or catches up a missed one, never sends twice. A survey
 * published after its opening is announced at the next pass.
 */
#[AsMessageHandler]
final readonly class NotifySurveysHandler
{
    public function __construct(
        private SurveyRepositoryInterface $surveys,
        private SurveyAnnouncer $announcer,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(NotifySurveysMessage $message): void
    {
        $now = $this->clock->now();

        foreach ($this->surveys->findOpenAt($now) as $survey) {
            if ($survey->isDueForOpeningNotice($now)) {
                $survey->markOpeningNotified($now);
                $this->surveys->save($survey);
                $this->announcer->announce($survey, false);

                continue;
            }
            if ($survey->isDueForReminder($now)) {
                // A manual reminder in the last 24 hours stands in for the automatic one.
                $send = !$survey->wasRemindedRecently($now);
                $survey->markAutomaticReminder($now, $send);
                $this->surveys->save($survey);
                if ($send) {
                    $this->announcer->announce($survey, true);
                }
            }
        }
    }
}
