<?php

declare(strict_types=1);

namespace App\Surveys\Infrastructure\Adapter;

use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Surveys\Application\Port\SurveyEventInterface;
use App\Surveys\Application\Support\SurveyEventPeriod;

/** Reads a survey's event in the Events context (story 51.1). */
final readonly class RepositorySurveyEvent implements SurveyEventInterface
{
    public function __construct(private EventRepositoryInterface $events)
    {
    }

    public function period(string $eventId): ?SurveyEventPeriod
    {
        $event = $this->events->findById($eventId);

        return null === $event ? null : new SurveyEventPeriod($event->getStartsAt(), $event->getEndsAt(), $event->getTitle());
    }
}
