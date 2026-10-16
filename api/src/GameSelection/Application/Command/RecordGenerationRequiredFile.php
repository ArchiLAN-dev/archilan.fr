<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Support\RequiredGenerationFile;
use Psr\Log\LoggerInterface;

/**
 * A generation failed for want of a file (story 44.3): the game whose world asked for it now requires it
 * from the launcher. The failure names a setting (`DK3Settings.rom_file`), not a game: among the games the
 * generation held, the one that declares that setting is the culprit; a lone game is the culprit anyway.
 */
final readonly class RecordGenerationRequiredFile
{
    public function __construct(
        private GameRepositoryInterface $games,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $candidateGameIds the games the failed generation held
     *
     * @return string|null the id of the game now requiring the file, null when none could be told apart
     */
    public function record(array $candidateGameIds, RequiredGenerationFile $file): ?string
    {
        $candidates = $this->games->findByIds(array_values(array_unique($candidateGameIds)));
        $declaring = array_values(array_filter($candidates, static fn (Game $game): bool => $game->knowsRequiredFile($file->setting)));
        $game = match (true) {
            1 === \count($declaring) => $declaring[0],
            [] === $declaring && 1 === \count($candidates) => $candidates[0],
            default => null,
        };
        if (null === $game) {
            $this->logger->info('game.required_file_unattributed', ['setting' => $file->setting, 'candidates' => \count($candidates), 'declaring' => \count($declaring)]);

            return null;
        }

        if ($game->markRequiredAtGeneration($file->setting, $file->file, $file->extension, $file->description, $file->md5s)) {
            $this->games->save($game);
            $this->logger->info('game.required_file_proven', ['gameId' => $game->getId(), 'setting' => $file->setting]);
        }

        return $game->getId();
    }
}
