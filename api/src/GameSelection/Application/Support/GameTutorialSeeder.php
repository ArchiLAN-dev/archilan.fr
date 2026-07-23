<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Support;

use App\GameSelection\Application\Port\GameCatalogLinksProviderInterface;
use App\GameSelection\Domain\Entity\Game;

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
     * @return list<array{type: string, title: string, description: string}>
     */
    public function buildFor(Game $game): array
    {
        $steps = [];

        if ($game->isBundledWithAp()) {
            $steps[] = [
                'type' => 'note',
                'title' => 'Rien à installer',
                'description' => self::withLinks(
                    'Ce jeu est inclus dans Archipelago : aucun apworld à installer.',
                    [['label' => 'Jeux supportés par Archipelago', 'url' => 'https://archipelago.gg/games']],
                ),
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
                'description' => self::withLinks(
                    "Télécharge l'apworld de ce jeu et place-le dans le dossier `custom_worlds` (ou `worlds`) de ton installation Archipelago.",
                    $links,
                ),
            ];
        }

        $steps[] = [
            'type' => 'yaml',
            'title' => 'Configurer le YAML',
            'description' => 'Génère puis personnalise ton fichier de configuration (YAML) pour ce jeu.',
        ];

        $steps[] = [
            'type' => 'connect',
            'title' => 'Se connecter',
            'description' => 'Lance le client Archipelago et connecte-toi à la session le jour J.',
        ];

        return $this->normalizer->normalize($steps)['steps'];
    }

    /**
     * Appends catalogue links as a markdown list. Links stopped being a step field in story 31.11 -
     * the description is markdown, so `[label](url)` carries them without a parallel structure.
     *
     * @param list<array{label: string, url: string|null}> $links
     */
    private static function withLinks(string $description, array $links): string
    {
        $bullets = [];
        foreach ($links as $link) {
            $label = trim($link['label']);
            $url = is_string($link['url'] ?? null) ? trim($link['url']) : '';

            if ('' === $url) {
                // The catalogue sheet carries label-only entries; they used to render as plain
                // bullets in the view, so they stay bullets here rather than disappearing.
                if ('' !== $label) {
                    $bullets[] = sprintf('- %s', $label);
                }
                continue;
            }

            $bullets[] = sprintf('- [%s](%s)', '' === $label ? $url : $label, $url);
        }

        return [] === $bullets ? $description : $description.'

'.implode('
', $bullets);
    }
}
