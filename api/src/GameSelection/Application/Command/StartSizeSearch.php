<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Application\Message\RunSizeSearchStepJob;
use App\GameSelection\Application\Support\YamlOptionValue;
use App\GameSelection\Application\Support\YamlTestTarget;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\SizeSearch;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Domain\Repository\SizeSearchRepositoryInterface;
use App\Sessions\Application\Service\SlotDurationEstimate;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 56.7: a player whose world goes past the target asks for a size option value that fits it.
 *
 * The search runs on the worker (`RunSizeSearchStepJob`), one solo generation at a time, each one taken from the
 * player's YAML test allowance (story 9.57). The YAML is the one shown in their editor; nothing is saved or changed.
 */
final readonly class StartSizeSearch
{
    public function __construct(
        private GameRepositoryInterface $games,
        private SizeSearchRepositoryInterface $searches,
        private YamlTestTarget $targets,
        private SlotDurationEstimate $durations,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    public function start(string $userId, string $gameId, string $yaml, ?string $eventId, ?string $runId): SizeSearchStart
    {
        if ('' === trim($yaml) || \strlen($yaml) > StartGameYamlTest::MAX_YAML_BYTES) {
            return new SizeSearchStart(SizeSearchOutcome::InvalidYaml);
        }
        $game = $this->games->findById($gameId);
        $gameName = $game?->getArchipelagoGameName();
        if (!$game instanceof Game || null === $gameName || !$game->isApworldReady() || Game::AVAILABILITY_UNAVAILABLE === $game->getAvailability()) {
            return new SizeSearchStart(SizeSearchOutcome::UnknownGame);
        }
        if ($game->needsAFileAtGeneration()) {
            return new SizeSearchStart(SizeSearchOutcome::RequiresFile);
        }

        $options = $this->options($game, $gameName, $yaml);
        if ([] === $options) {
            return new SizeSearchStart(SizeSearchOutcome::NoSizeOptions);
        }
        $target = $this->targets->hours($userId, $eventId, $runId);
        if (null === $target || $target <= 0) {
            return new SizeSearchStart(SizeSearchOutcome::NoTarget);
        }
        if (null === $this->durations->forSlot($gameId, [$userId], 1)->hours) {
            return new SizeSearchStart(SizeSearchOutcome::UnknownPace);
        }

        $now = $this->clock->now();
        $round = bin2hex(random_bytes(8));
        $search = $this->searches->findById(SizeSearch::idFor($userId, $gameId));
        if ($search instanceof SizeSearch) {
            $search->restart($round, $yaml, $target, $options, $now);
        } else {
            $search = SizeSearch::start($userId, $gameId, $round, $yaml, $target, $options, $now);
        }
        $this->searches->save($search);
        $this->bus->dispatch(new RunSizeSearchStepJob($search->getId(), $round));

        return new SizeSearchStart(SizeSearchOutcome::Started, $search->getId());
    }

    /**
     * The designated size options that are numeric and that the YAML gives a value.
     *
     * @return list<array{key: string, min: int, max: int, current: int}>
     */
    private function options(Game $game, string $gameName, string $yaml): array
    {
        $types = $game->getEffectiveOptionTypes() ?? [];
        $options = [];
        foreach ($game->getSizeOptions() as $key) {
            $type = $types[$key] ?? null;
            if (null === $type || !is_int($type['min'] ?? null) || !is_int($type['max'] ?? null) || $type['min'] >= $type['max']) {
                continue;
            }
            $default = $type['default'] ?? null;
            $current = YamlOptionValue::current($yaml, $gameName, $key, $type['min'], $type['max'], is_int($default) ? $default : null);
            if (null !== $current) {
                $options[] = ['key' => $key, 'min' => $type['min'], 'max' => $type['max'], 'current' => $current];
            }
        }

        return $options;
    }
}
