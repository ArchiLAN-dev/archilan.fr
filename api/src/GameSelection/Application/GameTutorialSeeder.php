<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\Game;

/**
 * Composes a default install tutorial for a game from the data we already have (story 31.1):
 * bundled → "nothing to install"; otherwise an apworld step carrying the source URL + the
 * catalog-sheet links; always a YAML and a connect step. The result is passed through
 * {@see InstallStepsNormalizer} so links are validated (http/https) exactly like authored steps.
 */
final readonly class GameTutorialSeeder
{
    public function __construct(
        private GameCatalogLinksProviderInterface $catalogLinks,
        private InstallStepsNormalizer $normalizer,
    ) {
    }

    /**
     * @return list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>
     */
    public function buildFor(Game $game): array
    {
        $steps = [];

        if ($game->isBundledWithAp()) {
            $steps[] = [
                'type' => 'note',
                'title' => 'Rien à installer',
                'description' => 'Ce jeu est inclus dans Archipelago : aucun apworld à installer.',
                'links' => [
                    ['label' => 'Jeux supportés par Archipelago', 'url' => 'https://archipelago.gg/games'],
                ],
            ];
        } else {
            $links = [];
            $sourceUrl = $game->getApworldSourceUrl();
            if (null !== $sourceUrl && '' !== $sourceUrl) {
                $links[] = ['label' => "Source de l'apworld", 'url' => $sourceUrl];
            }
            foreach ($this->catalogLinks->linksFor($game->getCatalogSheetName(), $game->getArchipelagoGameName(), $game->getName()) as $link) {
                $links[] = $link;
            }

            $steps[] = [
                'type' => 'apworld',
                'title' => "Installer l'apworld",
                'description' => "Télécharge l'apworld de ce jeu et place-le dans le dossier `custom_worlds` (ou `worlds`) de ton installation Archipelago.",
                'links' => $links,
            ];
        }

        $steps[] = [
            'type' => 'yaml',
            'title' => 'Configurer le YAML',
            'description' => 'Génère puis personnalise ton fichier de configuration (YAML) pour ce jeu.',
            'links' => [],
        ];

        $steps[] = [
            'type' => 'connect',
            'title' => 'Se connecter',
            'description' => 'Lance le client Archipelago et connecte-toi à la session le jour J.',
            'links' => [],
        ];

        return $this->normalizer->normalize($steps)['steps'];
    }
}
