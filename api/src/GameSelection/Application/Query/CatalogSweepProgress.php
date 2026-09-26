<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

use App\GameSelection\Domain\Service\ArchipelagoImageFreshness;
use App\Sessions\Application\Port\RunnerGatewayInterface;

/**
 * How far the rolling catalogue test has come on the image in use (story 38.9): the served apworlds
 * whose verdict was produced on it, out of all the served apworlds of enabled games - an apworld
 * served by two games counts once. Null when the runner does not say which image runs.
 */
final readonly class CatalogSweepProgress
{
    public function __construct(
        private ServedApworldsQueryInterface $servedApworlds,
        private RunnerGatewayInterface $runnerGateway,
    ) {
    }

    public function progress(): ?CatalogSweepProgressView
    {
        $runtime = $this->runnerGateway->fetchRuntime();
        if (null === $runtime) {
            return null;
        }
        $verdicts = $this->runnerGateway->fetchApworldPreflights();

        $hashes = [];
        foreach ($this->servedApworlds->servedApworlds() as $served) {
            if (!$served->disabled) {
                $hashes[] = $served->apworldHash;
            }
        }
        $hashes = array_values(array_unique($hashes));

        $tested = 0;
        foreach ($hashes as $hash) {
            $verdict = $verdicts[$hash] ?? null;
            if (null !== $verdict && true === ArchipelagoImageFreshness::isCurrent($verdict['image'] ?? null, $verdict['imageId'] ?? null, $runtime['apImage'], $runtime['apImageId'])) {
                ++$tested;
            }
        }

        return new CatalogSweepProgressView($runtime['apImage'], $tested, \count($hashes));
    }
}
