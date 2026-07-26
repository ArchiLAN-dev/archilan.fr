<?php

declare(strict_types=1);

namespace App\Sessions\Application\Handler;

use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Message\BuildSessionRecapJob;
use App\Sessions\Application\Port\AchievementRecomputeTriggerInterface;
use App\Sessions\Application\Port\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Application\Support\RecapGraph;
use App\Sessions\Application\Support\RecapSuperlativesCalculator;
use App\Sessions\Application\Support\SpoilerGraphParser;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionRecap;
use App\Sessions\Domain\Repository\SessionRecapRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Builds the {@see SessionRecap} projection from the generation spoiler.
 *
 * Reconciliation: the parser works in slot-name space (all the spoiler exposes);
 * this handler joins slot name -> slot id / goal time via the session slots so
 * the stored projection is entirely slot-id-keyed and joins cleanly to the
 * podium. A missing or unreadable spoiler yields a stats-only recap (empty
 * graph) rather than a failure - the public page then falls back to the podium.
 */
#[AsMessageHandler]
final readonly class BuildSessionRecapJobHandler
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SessionSpoilerArtifactReaderInterface $spoilerReader,
        private SpoilerGraphParser $parser,
        private RecapSuperlativesCalculator $superlatives,
        private SessionRecapRepositoryInterface $recaps,
        private RegistrationRepositoryInterface $registrations,
        private AchievementRecomputeTriggerInterface $achievementRecomputeTrigger,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BuildSessionRecapJob $job): void
    {
        $session = $this->sessions->findById($job->sessionId);
        if (null === $session) {
            $this->logger->warning('session.recap.build.session_missing', ['sessionId' => $job->sessionId]);

            return;
        }

        if (Session::STATUS_FINISHED !== $session->getStatus()) {
            $this->logger->debug('session.recap.build.not_finished', ['sessionId' => $job->sessionId, 'status' => $session->getStatus()]);

            return;
        }

        $slots = $this->slots->findBySessionId($job->sessionId);

        /** @var array<string,string> $slotIdByName */
        $slotIdByName = [];
        /** @var array<string,?\DateTimeImmutable> $goalByName */
        $goalByName = [];
        foreach ($slots as $slot) {
            $slotIdByName[$slot->getSlotName()] = $slot->getSlotId() ?? $slot->getId();
            $goalByName[$slot->getSlotName()] = $slot->getGoalReachedAt();
        }

        $graph = $this->readGraph($session);
        $rawSuperlatives = $this->superlatives->calculate($graph, $goalByName);

        $nodes = [];
        foreach ($graph->nodes as $node) {
            $slotId = $slotIdByName[$node->slotName] ?? null;
            if (null === $slotId) {
                continue;
            }
            $nodes[] = ['slotId' => $slotId, 'slotName' => $node->slotName, 'game' => $node->game];
        }

        $edges = [];
        foreach ($graph->edges as $edge) {
            $fromSlotId = $slotIdByName[$edge->fromSlotName] ?? null;
            $toSlotId = $slotIdByName[$edge->toSlotName] ?? null;
            if (null === $fromSlotId || null === $toSlotId) {
                continue;
            }
            $edges[] = ['fromSlotId' => $fromSlotId, 'toSlotId' => $toSlotId, 'count' => $edge->count];
        }

        $localItems = [];
        foreach ($graph->localItemCounts as $slotName => $count) {
            $slotId = $slotIdByName[$slotName] ?? null;
            if (null === $slotId) {
                continue;
            }
            $localItems[] = ['slotId' => $slotId, 'count' => $count];
        }

        $superlatives = [];
        foreach ($rawSuperlatives as $superlative) {
            $slotId = $slotIdByName[$superlative->slotName] ?? null;
            if (null === $slotId) {
                continue;
            }
            $superlatives[] = [
                'key' => $superlative->key,
                'label' => $superlative->label,
                'slotId' => $slotId,
                'value' => $superlative->value,
            ];
        }

        $now = $this->clock->now();
        $existing = $this->recaps->findBySessionId($job->sessionId);
        if (null === $existing) {
            $this->recaps->save(new SessionRecap($job->sessionId, $now, $nodes, $edges, $localItems, $superlatives));
        } else {
            $existing->rebuild($now, $nodes, $edges, $localItems, $superlatives);
            $this->recaps->save($existing);
        }

        $this->logger->info('session.recap.built', [
            'sessionId' => $job->sessionId,
            'nodes' => \count($nodes),
            'edges' => \count($edges),
            'superlatives' => \count($superlatives),
        ]);

        // Story 32.4: the storeArchive recompute (30.26) runs before this build, so the superlative
        // facts only become visible if the participants are re-evaluated once the projection exists.
        $userIds = $this->resolveParticipantUserIds($slots);
        $this->achievementRecomputeTrigger->recomputeForUsers($userIds);
        $this->logger->info('session.recap.achievements_recompute_triggered', [
            'sessionId' => $job->sessionId,
            'users' => \count($userIds),
        ]);
    }

    /**
     * An event slot points at a registration row (registration.user_id is the player); a
     * personal-run slot stores the user id directly - mirror of
     * SessionLifecycleManager::resolveParticipantUserIds.
     *
     * @param list<\App\Sessions\Domain\Entity\SessionSlot> $slots
     *
     * @return list<string>
     */
    private function resolveParticipantUserIds(array $slots): array
    {
        $userIds = [];
        foreach ($slots as $slot) {
            $registration = $this->registrations->findById($slot->getRegistrationId());
            $userIds[$registration instanceof Registration ? $registration->getUserId() : $slot->getRegistrationId()] = true;
        }

        return array_keys($userIds);
    }

    private function readGraph(Session $session): RecapGraph
    {
        $outputKey = $session->getGeneratedOutputKey();
        if (null === $outputKey) {
            $this->logger->warning('session.recap.build.no_output_key', ['sessionId' => $session->getId()]);

            return new RecapGraph([], [], []);
        }

        $artifact = $this->spoilerReader->extractSpoiler($outputKey);
        if (null === $artifact) {
            $this->logger->warning('session.recap.build.spoiler_unreadable', ['sessionId' => $session->getId(), 'outputKey' => $outputKey]);

            return new RecapGraph([], [], []);
        }

        return $this->parser->parse($artifact->contents);
    }
}
