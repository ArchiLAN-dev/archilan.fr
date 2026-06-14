<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\SessionConfig\Application\SessionConfigResolver;
use App\SessionConfig\Domain\SessionType;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyTemplateRepositoryInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class LaunchWeeklyEntry
{
    public function __construct(
        private WeeklyRunRepositoryInterface $runs,
        private WeeklyEntryRepositoryInterface $entries,
        private WeeklyTemplateRepositoryInterface $templates,
        private GameRepositoryInterface $games,
        private WeeklyRunnerGatewayInterface $gateway,
        private ClockInterface $clock,
        private SessionConfigResolver $configResolver,
        private SessionRepositoryInterface $sessions,
    ) {
    }

    /**
     * @return array{entryId: string, externalSessionId: string, connectionInfo: array{host: string, port: int, password: string|null}}
     */
    public function execute(string $weeklyRunId, string $entryId, string $userId): array
    {
        $run = $this->runs->findById($weeklyRunId);
        if (!$run instanceof WeeklyRun) {
            throw new \DomainException('run_not_found');
        }

        if (WeeklyRun::STATUS_ACTIVE !== $run->getStatus()) {
            throw new \DomainException('run_not_active');
        }

        $outputKey = $run->getGeneratedOutputKey();
        if (null === $outputKey) {
            throw new \DomainException('run_not_generated');
        }

        $template = $this->templates->findById($run->getTemplateId());
        if (null === $template) {
            throw new \DomainException('run_not_generated');
        }

        $entry = $this->entries->findById($entryId);
        if (!$entry instanceof WeeklyEntry) {
            throw new \DomainException('entry_not_found');
        }

        if ($entry->getWeeklyRunId() !== $weeklyRunId || $entry->getUserId() !== $userId) {
            throw new \DomainException('forbidden');
        }

        if (null !== $entry->getExternalSessionId()) {
            throw new \DomainException('session_already_started');
        }

        // The apworld hash (orchestrator content hash, stored on the game) lets the launch
        // configure the entry session so the orchestrator can stage worlds for reachability.
        $game = $this->games->findById($template->getGameId());
        $apworldHash = $game instanceof Game ? $game->getApworldHash() : null;
        if (null === $apworldHash || '' === $apworldHash) {
            throw new \DomainException('run_not_generated');
        }

        // Resolve the effective server config: the weekly profile ⊕ the per-template override
        // (admin-only; keyed by template id so it applies to all entries of the template). The join
        // password travels via its own argument; the rest become orchestrator server_options.
        $config = $this->configResolver->resolve(SessionType::Weekly, $template->getId());
        $serverOptions = $config->server->toServerFlags();
        unset($serverOptions['password']);

        // The run's generated world is already built; launch reuses it (no regeneration).
        $result = $this->gateway->launchEntry(
            $entryId,
            $apworldHash,
            $template->getYamlConfig(),
            $outputKey,
            $serverOptions,
            $config->server->joinPassword,
        );

        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $entry->launch($result['externalSessionId'], $now, $result['connectionInfo'], $result['bridgePort']);

        // Register a Session aggregate (story 17.13) so the entry's container is tracked by the shared
        // session lifecycle: idle/stopped/crashed webhooks find it and the owner can relaunch it from
        // the retained save. The orchestrateur session id equals the entryId; eventId carries the
        // weeklyRunId (column overloaded as for personal runs).
        $session = Session::createRunning(
            $result['externalSessionId'],
            $weeklyRunId,
            $result['connectionInfo']['host'],
            $result['connectionInfo']['port'],
            $result['connectionInfo']['password'],
            $result['bridgePort'],
            $now,
        );
        $this->sessions->persist($session);

        try {
            $this->entries->flush();
        } catch (\Throwable $e) {
            try {
                $this->gateway->terminate($result['externalSessionId']);
            } catch (\Throwable) {
            }

            throw $e;
        }

        return [
            'entryId' => $entryId,
            'externalSessionId' => $result['externalSessionId'],
            'connectionInfo' => $result['connectionInfo'],
        ];
    }
}
