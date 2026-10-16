# Story 38.16 - Des erreurs de génération lisibles, partout

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-16
**Origine :** demande de Jean le 2026-10-16 - revoir l'UI/UX des erreurs de génération, côté admin et côté joueur.
**Dépend de:** 38.15 (PR #853).

## Story

En tant qu'admin ou joueur d'ArchiLAN,
je veux qu'une génération ratée me dise en clair ce qui s'est passé et quoi faire, le journal brut restant à portée,
afin de corriger moi-même ce qui peut l'être au lieu de lire une trace Python.

## Constat (inventaire du 2026-10-16)

Sept écrans montrent une erreur de génération, chacun à sa façon :

| Écran | Aujourd'hui |
|---|---|
| Fiche jeu, onglet APWorld (admin) | « Voir l'erreur de génération » : fin du journal brut, sans résumé |
| Santé des apworlds (admin) | résumé d'une ligne + « Erreur complète » brute |
| Page de session (admin) | `session.error` (jamais envoyé : texte générique) + derniers logs bruts |
| Nouvelle version, test YAML (admin) | motif de rejet / erreur résumés, en police code |
| Choix des jeux et participant (joueur) | message résumé du test du slot dans un bloc de code |
| Page de la run (joueur) | erreurs par slot en liste + « Détails techniques » |
| Connexion à une session d'événement (joueur) | « La session a rencontré une erreur. », rien d'autre |

`GenerationFailureParser` (API) résume déjà : fichier requis (phrase française), YAML invalide, exception Python
(`OptionError: ...`, `FillError: ...`), délai dépassé (`preflight timed out`), slot en cause. Ces résumés sont
stockés comme du texte (listes de messages par slot, chaîne du test d'un slot).

## Décisions

1. **Un composant commun** `GenerationFailureNotice` : titre selon le type d'erreur, slot en cause, message résumé,
   conseil adapté au public (joueur ou admin), journal technique replié avec un bouton « Copier ».
2. **Catégories calculées côté front, à un seul endroit** (`generation-failure.ts`), à partir des messages que l'API
   a déjà résumés : les messages sont stockés comme du texte, leur donner une forme structurée changerait sept
   réponses d'API pour la même information. Catégories : fichier requis, YAML invalide, option refusée,
   remplissage impossible, délai dépassé, autre ; plus « fichier manquant » (décision 5).
3. **API** : seul ajout, le résumé (`summary`) du verdict de la fiche jeu, qui n'en avait pas.
4. Résumé d'abord, brut à la demande, partout.
5. **Fichier manquant ≠ fichier requis** (remarque de Jean le 2026-10-16, cas Beat Saber) : un `FileNotFoundError` sur
   un fichier que le monde lit sans le déclarer comme réglage (`/Archipelago/data/ranked_maps.json`, la liste de
   musiques) est bien un fichier à fournir, mais le lancement de la 44.5 ne sait pas le demander. Catégorie à part,
   « Fichier manquant », dont le conseil le dit.

## Critères d'acceptation

1. `classifyGenerationFailure(message)` reconnaît les six catégories ; textes (titre, conseil joueur, conseil admin)
   par catégorie ; tests unitaires sur des messages réels du parseur.
2. `GenerationFailureNotice` : icône + titre, slot éventuel, message, conseil, « Journal technique » replié avec
   « Copier » quand un journal est fourni.
3. Admin : onglet APWorld (résumé de l'API + journal), Santé des apworlds (résumé + erreur complète), page de session
   (erreurs par slot + derniers logs), nouvelle version (motif de rejet), résultat du test YAML.
4. Joueur : choix des jeux et page du participant (test du slot), page de la run (erreurs par slot + extrait du
   journal), connexion à la session d'événement (texte qui dit quoi faire).
5. `pnpm gates` et `composer gates` verts ; vérification visuelle sur la fiche DKC3 (fichier requis).

## Tâches

- [x] Module de classification et composant commun, tests.
- [x] API : `summary` dans le verdict de la fiche jeu, test.
- [x] Écrans admin.
- [x] Écrans joueur.
- [x] Gates, vérification visuelle.

## Dev Agent Record

### Completion Notes

- `features/games/generation-failure.ts` (classification + textes joueur/admin) et `generation-failure-notice.tsx`
  (`GenerationFailureNotice`, `TechnicalLog` avec « Copier »).
- API : `AdminGameLibrary::preflightForGame` ajoute `summary` (`GenerationFailureParser::summarize`) à un verdict en
  échec ; test fonctionnel.
- Admin : onglet APWorld, liste des incidents (le bandeau garde son résumé d'une ligne), page de session (une
  carte pour l'échec ou la validation, une ligne par constat, journal une fois ; le plantage du serveur garde son
  bloc), motif de rejet de la nouvelle version, résultat du test YAML.
- Joueur : choix des jeux, page du participant, bannière d'échec de la run (constats par slot + journal replié),
  page de connexion à une session d'événement (texte qui dit quoi faire, sans promettre de notification).
- Vérifié dans le navigateur : fiche DKC3 (« Fichier requis manquant ») et Santé des apworlds (DKC3, Beat Saber).
- Gates : `pnpm gates` (1109 tests, build), `composer gates` (3079 tests, base isolée).

### Suite possible

- Fournir au lancement un fichier que le monde lit sans le déclarer (Beat Saber, `data/ranked_maps.json`) : la 44.x
  ne couvre que les réglages `host.yaml`. À instruire dans l'epic 44.

### File List

- `frontend/src/features/games/generation-failure.ts`, `.test.ts`, `generation-failure-notice.tsx`, `.test.tsx` (nouveaux)
- `frontend/src/features/admin/admin-game-apworld-tab.tsx`, `admin-games-api.ts`, `apworld-candidate-status.tsx`,
  `candidate-yaml-test.tsx`, `apworld-incident-list.tsx`, `admin-session-page.tsx`
- `frontend/src/features/personal-runs/personal-run-game-selection-page.tsx`, `personal-run-participant-detail-page.tsx`,
  `personal-run-detail-page.tsx`
- `frontend/src/features/events/session-connection-gate.tsx`
- `api/src/GameSelection/Application/Service/AdminGameLibrary.php`, `api/tests/Functional/AdminGameTestWithRequiredFileTest.php`

## Change Log

- 2026-10-16 : erreurs de génération lisibles sur les sept écrans.
