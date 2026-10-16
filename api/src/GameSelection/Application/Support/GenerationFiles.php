<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Domain\Entity\Game;

/**
 * The files a generation may read from the launcher (story 44.3), as the screens read them: public
 * metadata of the worlds - names, extensions, accepted MD5s - never a file. `neededAtGeneration` is what
 * makes one mandatory; the others are only declared, most serve the client alone.
 */
final class GenerationFiles
{
    private function __construct()
    {
    }

    /**
     * @return list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>, neededAtGeneration: bool}>
     */
    public static function ofGame(Game $game): array
    {
        return $game->getRequiredFiles();
    }

    /**
     * One entry per setting across the games of a party: two slots of the same game, or two games sharing a
     * setting, ask for the file once. Needed as soon as one of them needs it.
     *
     * @param list<Game> $games
     *
     * @return list<array{setting: string, file: string, extension: string, description: ?string, md5s: list<string>, neededAtGeneration: bool, games: list<string>}>
     */
    public static function forGames(array $games): array
    {
        $bySetting = [];
        foreach ($games as $game) {
            foreach ($game->getRequiredFiles() as $file) {
                $entry = $bySetting[$file['setting']] ?? [...$file, 'games' => []];
                $entry['neededAtGeneration'] = $entry['neededAtGeneration'] || $file['neededAtGeneration'];
                if (!\in_array($game->getName(), $entry['games'], true)) {
                    $entry['games'][] = $game->getName();
                }
                $bySetting[$file['setting']] = $entry;
            }
        }
        ksort($bySetting);

        return array_values($bySetting);
    }
}
