<?php

declare(strict_types=1);

namespace App\Community\Application;

interface EventCatalogueQueryInterface
{
    /**
     * Events the admin can scope an event-goal achievement to: every non-draft event (published /
     * in-progress / completed), newest first - so a finished event is pickable (story 30.32).
     *
     * @return list<array{id: string, title: string}>
     */
    public function selectableEvents(): array;

    /**
     * Whether an event with this id exists (any status) - used to reject a scoped fact that references no
     * real event.
     */
    public function exists(string $eventId): bool;
}
