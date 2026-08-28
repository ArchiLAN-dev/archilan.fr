# Story 9.52: Surcharge admin des valeurs de sous-options d'un dict

**Status:** draft
**Epic:** 9 - Multiworld generation pipeline & apworld introspection
**Date:** 2026-08-28
**Dépend de :** [9.51](9-51-dict-sub-option-values-from-schema.md) - qui pose le transport, la
persistance et le rendu select. Cette story n'ajoute qu'une deuxième source, autoritaire.
**Précédent :** [9.47](9-47-manual-platform-override.md) - même tension « l'outil décrit une chose,
l'admin en décrit une autre », même règle : surcharger sans écraser.

## Story

En tant qu'**admin qui cure la bibliothèque de jeux**,
je veux **déclarer moi-même les valeurs autorisées des sous-options d'un dict**,
afin que **les joueurs aient un select même quand l'apworld ne déclare rien de machine-lisible**.

## Context

La 9.51 lit ce que l'apworld déclare. Le problème est que la plupart ne déclarent rien : Archipelago
n'impose à un `OptionDict` ni typage des valeurs, ni énumération, ni `Schema`. Le cas qui a lancé le
sujet en est l'exemple exact - `GameOptions(OptionDict)` de Pokemon Platinum n'a qu'un `default` et une
docstring en prose, donc onze champs texte libres dans l'éditeur, et un joueur qui doit deviner que
`battle_style` veut `shift` ou `set`.

Aucune inférence automatique ne peut combler ça de façon fiable : le catalogue est écrit par des
dizaines de développeurs indépendants sans convention commune, et une valeur mal devinée qui contraint
un select est pire que pas de select. La seule source restante qui soit à la fois large et exacte,
c'est nous. La saisie est manuelle, mais elle est ponctuelle - une fois par jeu, seulement sur les dict
options qui gênent - et juste par construction quel que soit le style du dev.

### Le piège de stockage

`Game::recordOptionTypes()` (`Game.php:306`) **remplace le tableau entier** à chaque upload d'apworld
et à chaque backfill. Une curation rangée dans `optionTypes` serait donc effacée à la première
réintrospection. Elle doit vivre à côté et être fusionnée à la lecture - c'est le même mécanisme que
`overridePlatformFamilies()` a mis en place pour les plateformes IGDB en 9.47.

## Acceptance Criteria

**AC1 - Surcharger, pas écraser.** Un jeu peut porter une liste de valeurs autorisées par sous-clé de
dict, choisie par un admin, **stockée séparément** de `optionTypes`. Un ré-upload d'apworld, un
`BackfillGameOptionTypes` ou une régénération de gabarit mettent à jour l'introspection sans toucher à
la surcharge.

**AC2 - Une règle, un seul endroit.** Le spec effectif est `surcharge ?? introspecté`, résolu par sous-clé
(la surcharge d'une sous-clé ne masque pas les autres) par **une seule** fonction partagée, utilisée par
toutes les charges utiles qui exposent déjà `optionTypes`. Aucun appelant ne réimplémente la précédence.

**AC3 - Saisie admin.** Sur la page admin d'un jeu, pour chaque option de type `dict` du gabarit, l'admin
voit les sous-clés connues (défauts du gabarit, `validKeys`, spec introspecté) et peut saisir leurs
valeurs autorisées. Validation au enregistrement : valeurs scalaires, pas de doublon, pas de liste vide,
sous-clé inconnue rejetée.

**AC4 - Liste fermée ou suggestions.** Chaque sous-clé surchargée porte un drapeau explicite : liste
**fermée** (le select ne propose que ces valeurs) ou **ouverte** (select + « Autre… », par défaut).
C'est l'admin qui sait si l'énumération est exhaustive - `text_frame` (`1-20` ou `random`) et
`default_player_name` (`custom` attend une saisie libre) sont des listes ouvertes, `battle_style`
(`shift` / `set`) est fermée.

**AC5 - Réversible.** L'admin peut retirer la surcharge d'une sous-clé ou de l'option entière en une
action et retrouver la réponse de l'introspection. L'UI dit laquelle des deux est en vigueur.

**AC6 - Visible partout.** Le spec effectif est ce que voient tous les éditeurs YAML : inscription à un
event, personal runs, weekly runs, admin, réponse de configure. La surcharge n'est pas une décoration
d'admin.

**AC7 - Aucune valeur de joueur perdue.** Une valeur enregistrée hors de la liste surchargée est
conservée et affichée telle quelle, y compris sur une liste fermée : une curation faite après coup ne
réécrit jamais en silence le YAML d'un joueur. Elle est signalée à l'admin, pas au joueur.

**AC8 - Gates.** `composer gates` côté api, `pnpm gates` côté frontend.

## Tasks / Subtasks

- [ ] **Task 1** (AC1). Migration : colonne JSON nullable sur `game` pour la surcharge.
- [ ] **Task 2** (AC1, AC2). Domaine : value object du spec surchargé (sous-clé → valeurs + fermé/ouvert),
      `Game::overrideDictOptionValues()` / accesseur du spec effectif, et **le** résolveur partagé de
      précédence. Tests unitaires sur la fusion par sous-clé.
- [ ] **Task 3** (AC2, AC6). Brancher le résolveur sur les lecteurs existants d'`optionTypes`
      (`PersonalRunGameSelection`, `RegistrationGameSelection`, `AdminGameLibrary`, configure) plutôt
      que d'ajouter un champ parallèle dans chaque charge utile.
- [ ] **Task 4** (AC3, AC4, AC5). Application + Presentation : commande d'enregistrement validée,
      action de retrait, endpoint admin, détail exposant le spec effectif, ce qui est surchargé, et les
      sous-clés proposables. Tests.
- [ ] **Task 5** (AC3, AC4, AC5). Frontend admin : éditeur de valeurs par sous-clé dans
      `admin-game-editor.tsx`, drapeau fermé/ouvert, retour à l'introspection.
- [ ] **Task 6** (AC4, AC7). Frontend éditeur : `DictField` respecte le drapeau (liste fermée = pas
      d'« Autre… »), et conserve quand même une valeur enregistrée hors liste. Jest.
- [ ] **Task 7** (AC8). Gates verts.

## Dev Notes

- **Ne pas fusionner dans `optionTypes` au moment de l'écriture.** La fusion est une opération de
  **lecture** : écrire la surcharge dans `optionTypes` la ferait disparaître au prochain
  `recordOptionTypes()`, et perdrait au passage la distinction entre « l'apworld le dit » et « on l'a
  décidé », dont l'AC5 a besoin pour être réversible.
- **Réutiliser la forme de la 9.51**, pas une deuxième. La surcharge produit le même `{type, values?}`
  par sous-clé, plus le drapeau de fermeture. Le frontend ne doit connaître qu'une seule forme et
  ignorer d'où elle vient.
- **Où poser le drapeau.** Un spec issu du `Schema` d'un apworld (9.51) est traité comme **ouvert** :
  un Schema peut être exact sans être exhaustif. Seule une surcharge admin peut déclarer une liste
  fermée, parce qu'elle seule engage un humain sur l'exhaustivité.
- **Coût de saisie assumé, à ne pas noyer.** L'écran doit préremplir les sous-clés depuis les défauts du
  gabarit pour que l'admin n'ait qu'à remplir les valeurs. Sans ça, curer un `game_options` à onze
  clés est assez pénible pour ne jamais être fait.
- **Portée.** La surcharge porte sur les valeurs de sous-clés d'un dict, pas sur les noms de sous-clés
  (verrouillés depuis 4.17 / 9.33) ni sur les autres types d'options (`choice` a déjà ses valeurs par
  introspection).

### Project Structure Notes

- `api/src/GameSelection/Domain/Entity/Game.php` (`optionTypes`, `recordOptionTypes`,
  `overridePlatformFamilies` comme modèle).
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php` (`savePlatformFamilies` comme modèle
  de commande de surcharge).
- `api/src/GameSelection/Presentation/Controller/AdminGameLibraryController.php`.
- `api/src/PersonalRuns/Application/Service/PersonalRunGameSelection.php`,
  `api/src/Registrations/Application/Service/RegistrationGameSelection.php`.
- `frontend/src/features/admin/admin-game-editor.tsx`, `admin-game-library-api.ts`.
- `frontend/src/lib/archipelago-yaml.ts`, `frontend/src/features/events/yaml-option-editor.tsx`
  (`DictField`).

### References

- [Source: _bmad-output/implementation-artifacts/9-51-dict-sub-option-values-from-schema.md (moitié aval réutilisée)]
- [Source: _bmad-output/implementation-artifacts/9-47-manual-platform-override.md (patron surcharge/réversible)]
- [Source: _bmad-output/implementation-artifacts/9-33-authoritative-option-type-table-end-to-end.md]
- [Source: api/src/GameSelection/Domain/Entity/Game.php:306 (`recordOptionTypes` remplace tout)]
- [Source: api/src/GameSelection/Application/Service/AdminGameLibrary.php:176 (`savePlatformFamilies`)]
- [Source: https://github.com/ljtpetersen/platinum_archipelago/blob/master/options.py (`GameOptions` : ni `Schema`, ni `valid_keys`)]

## Change Log

| Date       | Change |
|------------|--------|
| 2026-08-28 | Créée. Couche de curation admin par-dessus l'introspection de la 9.51, pour les apworlds qui ne déclarent aucune valeur autorisée. Status: draft. |
