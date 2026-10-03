<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

interface EventPellesQueryInterface
{
    /**
     * The members registered to the event, cancelled registrations left out (story 41.2).
     *
     * @return list<string>
     */
    public function activeRegistrantIds(string $eventId): array;

    /**
     * Every member still holding pelles of an event that has ended (story 41.2 AC7).
     *
     * @return list<array{eventId: string, eventTitle: string, userId: string, balance: int}>
     */
    public function endedEventBalances(\DateTimeImmutable $now): array;

    /**
     * The admin page of an event's pelles (story 41.2 AC1), or null when the event does not exist. Gold pelles
     * converted at the end are counted from the conversion lines, destroyed ones from the expiry lines.
     *
     * @return array{
     *     eventId: string,
     *     eventTitle: string,
     *     endsAt: string,
     *     ended: bool,
     *     distributed: int,
     *     inCirculation: int,
     *     converted: int,
     *     destroyed: int,
     *     participants: list<array{userId: string, displayName: string, balance: int, banned: bool}>
     * }|null
     */
    public function eventPage(string $eventId, \DateTimeImmutable $now): ?array;
}
