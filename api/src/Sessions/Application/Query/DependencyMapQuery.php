<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Registrations\Application\Query\PresenceTimelineQueryInterface;
use App\Sessions\Application\Port\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Application\Port\SpoilerArtifact;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Service\DependencyRisk;
use App\Sessions\Domain\Service\SpoilerPlaythrough;
use App\Sessions\Domain\ValueObject\RiskyDependency;
use App\Shared\Application\Exception\NotFoundException;

/**
 * Story 52.7: before the launch, who risks waiting for whom in each generated session of an event, from the
 * spoiler's playthrough and the declared windows (story 52.2). Read only: nothing is regenerated or changed - the
 * staff compares the scores, then launches the session they keep.
 */
final readonly class DependencyMapQuery
{
    private const array DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];

    public function __construct(
        private PresenceTimelineQueryInterface $presence,
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SessionSpoilerArtifactReaderInterface $spoilers,
    ) {
    }

    /**
     * @throws NotFoundException when the event does not exist
     */
    public function forEvent(string $eventId, int $sphereLimit = DependencyRisk::DEFAULT_SPHERE_LIMIT): DependencyMap
    {
        $timeline = $this->presence->timeline($eventId);
        if (null === $timeline) {
            throw new NotFoundException('Événement introuvable.');
        }
        $sphereLimit = max(1, min(DependencyRisk::MAX_SPHERE_LIMIT, $sphereLimit));

        $names = [];
        $windows = [];
        foreach ($timeline['registrants'] as $registrant) {
            $names[$registrant['registrationId']] = $registrant['displayName'];
            foreach ($registrant['windows'] as $window) {
                $windows[$registrant['registrationId']][] = [new \DateTimeImmutable($window['startsAt']), new \DateTimeImmutable($window['endsAt'])];
            }
        }
        $declared = [] !== $windows;
        $halfDays = DependencyRisk::halfDays(new \DateTimeImmutable($timeline['eventStartsAt']), new \DateTimeImmutable($timeline['eventEndsAt']));

        $generations = [];
        foreach ($this->sessions->findByEventId($eventId) as $session) {
            $outputKey = $session->getGeneratedOutputKey();
            if (null === $outputKey) {
                continue;
            }
            if (!$declared) {
                $generations[] = new DependencyGeneration($session->getId(), $session->getStatus(), 'skipped', 0, []);
                continue;
            }

            $people = [];
            foreach ($this->slots->findBySessionId($session->getId()) as $slot) {
                $people[$slot->getSlotName()] = $slot->getRegistrationId();
            }
            $spoiler = $this->spoilers->extractSpoiler($outputKey);
            $steps = $spoiler instanceof SpoilerArtifact ? SpoilerPlaythrough::parse($spoiler->contents, array_keys($people)) : null;
            if (null === $steps) {
                $generations[] = new DependencyGeneration($session->getId(), $session->getStatus(), 'missing', 0, []);
                continue;
            }

            $risks = DependencyRisk::assess($steps, $people, $windows, $halfDays, $sphereLimit);
            $generations[] = new DependencyGeneration($session->getId(), $session->getStatus(), 'read', count($risks), $this->pairs($risks, $names, $halfDays));
        }

        return new DependencyMap($declared, $sphereLimit, $generations);
    }

    /**
     * @param list<RiskyDependency>                                     $risks
     * @param array<string, string>                                     $names
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $halfDays
     *
     * @return list<DependencyPair>
     */
    private function pairs(array $risks, array $names, array $halfDays): array
    {
        $grouped = [];
        foreach ($risks as $risk) {
            $key = $risk->waiting."\0".$risk->holding;
            $grouped[$key]['waiting'] = $names[$risk->waiting] ?? $risk->receiver;
            $grouped[$key]['holding'] = $names[$risk->holding] ?? $risk->finder;
            $grouped[$key]['items'][] = $risk->item;
            foreach ($risk->halfDays as $index) {
                $grouped[$key]['halfDays'][$index] = true;
            }
        }

        $pairs = [];
        foreach ($grouped as $group) {
            $indexes = array_keys($group['halfDays'] ?? []);
            sort($indexes);
            $labels = [];
            foreach ($indexes as $index) {
                if (isset($halfDays[$index])) {
                    $labels[] = self::label($halfDays[$index][0]);
                }
            }
            $pairs[] = new DependencyPair($group['waiting'], $group['holding'], $group['items'], $labels);
        }
        usort($pairs, static fn (DependencyPair $a, DependencyPair $b): int => count($b->items) <=> count($a->items));

        return $pairs;
    }

    private static function label(\DateTimeImmutable $start): string
    {
        $paris = $start->setTimezone(new \DateTimeZone('Europe/Paris'));

        return self::DAYS[(int) $paris->format('w') % 7].' '.((int) $paris->format('G') < 12 ? 'matin' : 'après-midi');
    }
}
