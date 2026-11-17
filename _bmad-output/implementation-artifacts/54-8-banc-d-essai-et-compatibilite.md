# Story 54.8 - Banc d'essai et compatibilité

**Status:** review
**Epic:** 54 - Trackers de jeu, les packs PopTracker sur le site (`_bmad-output/planning-artifacts/epics/epic-54-trackers-poptracker.md`)
**Date:** 2026-11-16
**Branche:** `feature/epic-54-story-8-banc-d-essai-et-compatibilite`
**Dépend de:** 54.2, 54.5.

## Story

En tant qu'admin,
je veux essayer un pack avant de le proposer aux joueurs et savoir s'il fonctionne sur le site,
afin de ne pas activer un tracker qui plante ou qui ment.

## Contexte

- Un pack importé est inactif tant qu'un admin ne l'active pas (54.2). L'archive d'un pack inactif reste lisible par
  un admin.
- Le moteur remonte `error` et `unsupported_api` (54.4). Le panneau d'erreurs existe sur l'écran du tracker (54.5).
- Le moteur ne couvre que l'API utilisée par le pack MM, plus ce que la spec rend trivial. D'autres packs utiliseront
  d'autres fonctions (`Tracker:CreateLuaItem`, `canvas`, `ScriptHost:AddOnFrameHandler`...).

## Décisions

1. **Essayer** : bouton « Essayer » sur chaque pack de la fiche admin du jeu (actif ou non). Il ouvre le tracker de
   54.5 sur la version choisie, en manuel (`/jeux/[slug]/tracker?pack={id}&version={versionId}&essai=1`, réservé aux
   admins pour une version non servie).
2. **Rapport de compatibilité** : à la fin du chargement de chaque variante en essai, le front envoie
   `POST /api/v1/admin/tracker-pack-versions/{versionId}/compatibility`
   `{variantUid, status: ok|warnings|failed, errors: [{kind, message, file?, line?}] (≤ 50), unsupportedApis: string[],
   engineVersion}`. Le dernier rapport par variante est gardé sur la version.
3. **Affichage** : pastille par pack dans l'admin (« Compatible », « Avec avertissements », « Ne se charge pas »,
   « Pas encore essayé ») et détail dépliable (erreurs, API manquantes).
4. **Activation** : activer un pack dont une variante est en échec demande une confirmation explicite (pas de blocage :
   l'admin peut juger que la variante cassée n'est pas utile).
5. **Campagne** : lister les packs PopTracker des 10 jeux les plus joués sur le site (requête sur les slots des
   6 derniers mois), les importer en local, les essayer, et compléter le shim pour les API manquantes les plus
   fréquentes. Le reste est noté dans `deferred-work.md` avec le pack concerné. Le résultat (jeu, pack, état, API
   manquantes) est consigné dans le Dev Agent Record.
6. **Versions du moteur** : `engineVersion` (constante du moteur, incrémentée à chaque ajout au shim) permet de
   repérer les rapports à refaire après une évolution.

## Critères d'acceptation

1. Un admin essaie un pack inactif sans que les joueurs y aient accès.
2. Après l'essai, la fiche du jeu affiche l'état de compatibilité de chaque variante et le détail des erreurs.
3. Activer un pack en échec demande une confirmation.
4. La campagne couvre 10 jeux, et au moins 7 de leurs packs se chargent sans erreur à la fin de la story.

## Tests et gates

5. Fonctionnels : rapport (admin seulement, bornes de taille, remplacement du précédent), vue admin.
6. Front : bouton d'essai, envoi du rapport, pastilles, confirmation d'activation.
7. Moteur : un test par API ajoutée au shim pendant la campagne.
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - Mode essai du tracker, accès admin aux versions non servies.
- [x] **Task 2** (AC 2, 3) - Endpoint de rapport, stockage, pastilles, confirmation.
- [x] **Task 3** (AC 4) - Campagne sur 10 jeux, compléments du shim, `deferred-work.md`.
- [x] **Task 4** (AC 8, pour les tâches 1 et 2) - Gates.

## Dev Agent Record

### Livré (tâches 1, 2, 4)

- **Essai** : `/jeux/[slug]/tracker?pack=&version=&essai=1` rend `TrackerTrial` (client). Le rendu serveur est anonyme,
  donc le pack est lu dans le navigateur via l'API admin (`GET /admin/games/{id}/tracker-packs`). Un joueur n'obtient
  rien (« réservé aux admins »). Les versions conservées exposent maintenant `rootPath`, `sha256` et `variants`,
  ce qui permet d'essayer n'importe quelle version gardée. L'archive est téléchargée avec `credentials: "include"` :
  l'API la sert déjà aux admins pour un pack non proposé (54.2).
- **Rapport** : `useLoadReport` envoie un rapport par variante, 1,5 s après la fin du chargement (pour inclure les
  erreurs du premier rendu). Il reprend les messages du panneau d'erreurs : « Fonction non prise en charge : X »
  devient une API manquante, « Élément d'affichage non géré : t » devient `widget:t`, le reste devient des erreurs.
  Un chargement en échec donne `failed`. `ENGINE_VERSION` = 1 (`engine/engine-version.ts`).
- **API** : `POST /api/v1/admin/tracker-pack-versions/{versionId}/compatibility`, table `tracker_pack_compatibility`
  (clé version + variante, le dernier rapport remplace le précédent). Textes tronqués, listes au-delà de 50 erreurs ou
  100 API refusées (422), forme invalide en 400, variante inconnue en 404. Les rapports d'une version expirée
  partent avec elle.
- **Admin** : pastille globale (« Compatible », « Avec avertissements », « Ne se charge pas », « Pas encore
  essayé ») et détail dépliable par variante (erreurs, API manquantes, « à refaire » si le moteur a changé depuis),
  plus un bouton « Essayer ». Proposer un pack dont une variante est en échec affiche une confirmation dans la page
  (« Proposer quand même » / « Annuler »).
- **Tests** : `TrackerPackCompatibilityTest` (3 tests), `admin-tracker-pack-compatibility.test.tsx` (pastilles,
  lien, confirmation), 4 tests d'essai dans `tracker-screen.test.tsx`.

### Campagne (tâche 3, AC 4, AC 7)

Jeux retenus d'après l'historique public des 293 joueurs du sitemap depuis le 2026-05-17 (pas d'accès direct à la
base de prod), parmi ceux qui ont un pack PopTracker publié. A Link Between Worlds s'ajoute pour l'ArchiWAN #2. Les
packs sont essayés hors navigateur par `engine/pack-campaign.test.ts` (ignoré sans `TRACKER_CAMPAIGN_DIR`), avec le
même moteur que l'écran, toutes variantes comprises.

| Jeu | Pack | État final | Ce qui manquait au site |
|---|---|---|---|
| Celeste (Open World) | Zeeveez v2.1.2 | OK (2 variantes) | `ScriptHost:RunScriptAsync`, widget `canvas` |
| Cuphead | TheArcticFox42 v1.2.2 | OK (2 variantes) | règles circulaires résolues par point fixe |
| Hollow Knight | master-cheaf117 2.1 | OK | `ScriptHost:CreateLuaItem` |
| Inscryption | DrBibop 1.0.2 | OK (2 variantes) | codes d'un item progressif portés à côté de ses étapes |
| Kirby Squeak Squad | Talcerase v0.2.1 | OK | rien |
| Pokémon Platinum | palex00 2.2.1 | OK (2 variantes) | `ScriptHost:CreateLuaItem`, `ImageReference:FromImageReference` avec mods |
| Spyro 3 | 19techguy91 (main) | Se charge, 1 erreur du pack | chemin à la casse près (Windows) ; le pack appelle `Tracker:AddMemoryWatch`, qui n'existe pas dans PopTracker non plus |
| A Link Between Worlds | Legendgreat v1.4.2 | Erreur du pack | `floors.json` absent de la release |
| Luigi's Mansion | DevinLB v0.1.1 | Erreur du pack | `scripts/utils.lua` et `ghost_rando_table.lua` absents |
| Majora's Mask | G4M3RL1F3 v0.9.5.3 | Erreur du pack | `scripts/inverse_rules.lua` absent |

7 packs sur 10 se chargent ; les 3 restants échouent sur des fichiers que leur release ne contient pas (PopTracker
échoue de la même façon). Ajouts au moteur (`ENGINE_VERSION` = 2), un test chacun dans
`tracker-engine-shim.test.ts`, `pack-files.test.ts` et `tracker-widgets.test.tsx` :

- items Lua (`ScriptHost:CreateLuaItem`) : codes, dessin (icône, nom, badge), clics, `FindObjectForCode` ;
- scripts asynchrones (`RunScriptAsync`, `RunStringAsync`) dans leur propre environnement, exécutés après la passe
  de logique, puis leur callback ;
- codes d'un item progressif portés au niveau de l'item ;
- règles circulaires : point fixe en 20 passes au plus, comme PopTracker, au lieu d'une erreur ;
- une erreur de `init.lua` est journalisée et le pack garde ce qui a été chargé avant ; une fonction absente de
  PopTracker lui-même est une erreur du pack, plus une « fonction non prise en charge » ;
- `Tracker:OpenLink` (sans effet), chemin de fichier à la casse près, widget `canvas`.

Reportés : voir `deferred-work.md`, section epic 54.
