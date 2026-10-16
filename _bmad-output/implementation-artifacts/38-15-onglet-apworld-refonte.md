# Story 38.15 - Refonte de l'onglet APWorld de la fiche jeu

**Status:** review
**Epic:** 38 - Santé et mise à jour automatique des apworlds
**Date:** 2026-10-16
**Origine :** demande de Jean le 2026-10-16 - l'onglet APWorld accumule trop de choses, ses sections sont collées,
les actions ponctuelles (lancer un test de génération) devraient passer par des modales.
**Dépend de:** 44.6 (PR #852, panneau ROM des tests admin), 44.5 (PR #849, panneau partagé). Front seulement, aucune API.

## Story

En tant qu'admin d'ArchiLAN,
je veux un onglet APWorld où chaque sujet a sa carte et où les actions ponctuelles s'ouvrent dans une modale,
afin de voir d'un coup d'œil l'état de l'apworld d'un jeu et d'agir sans faire défiler un long formulaire.

## Constat (2026-10-16, fiche Donkey Kong Country 3)

- Une seule carte « Fichier .apworld » empile cinq sujets avec des titres de même poids : état de la version en ligne
  et test de génération (verdict, relance, dérogation, panneau ROM, image, explication, erreur), nouvelle version et
  son test YAML, formulaire de remplacement, template YAML de 240 lignes toujours ouvert, valeurs des réglages groupés.
- La carte « Fichiers du joueur » touche la précédente : le conteneur de l'onglet n'a aucun espacement.
- Le panneau ROM (44.6) s'ouvre au milieu de la carte d'état ; les deux tests sont à deux endroits.
- Le remplacement mélange un champ fichier natif et des boutons stylés ; la liste des fichiers d'une release GitHub
  s'insère dans la page.
- L'éditeur des réglages groupés est sans accents (« reglages groupes », « Valeurs enregistrees »).

## Décisions

1. Cartes séparées (espacement régulier), chacune avec un titre, une ligne d'explication courte et ses actions à droite.
2. Modales (composant `Dialog` de la 39.11) pour les actions ponctuelles : tester la génération, tester avec un YAML,
   remplacer l'apworld ; confirmation (`ConfirmDialog`) pour « Autoriser malgré l'échec ».
3. Template YAML et réglages groupés : cartes repliées par défaut (choix de Jean), dépliées par « Modifier » / « Ouvrir ».
4. Aucun changement d'API ni de comportement : mêmes appels, mêmes messages, même polling du verdict.

## Critères d'acceptation

1. **Structure.** L'onglet est une pile de cartes espacées : « Version en ligne », « Nouvelle version » (si une
   version candidate existe), « Fichiers du joueur » (si déclarés), « Template YAML », « Valeurs des réglages groupés »
   (si l'apworld en a). Sans apworld, la carte « Version en ligne » le dit et propose « Ajouter un apworld ».
2. **Version en ligne.** Les quatre infos sur une ligne compacte (jeu Archipelago, date, empreinte courte, lignes du
   template), puis le verdict du test (libellé, date, « réussi avec <fichier> » de la 44.6, avertissement, image,
   dérogation active, erreur repliée). Actions : « Tester la génération… », « Remplacer… », et selon le verdict
   « Autoriser malgré l'échec » (avec confirmation) ou « Retirer la dérogation ».
3. **Modale « Tester la génération ».** Explique le test (options par défaut, une seule seed), affiche le panneau ROM
   quand un fichier est exigé, « Lancer le test » ferme la modale une fois le test accepté ; une erreur reste affichée
   dans la modale.
4. **Nouvelle version.** Le bloc de la 38.6/38.14 dans sa carte ; « Tester avec un YAML… » ouvre une modale (YAML,
   panneau ROM, lancement, suivi du résultat jusqu'au verdict).
5. **Modale « Remplacer l'apworld ».** Deux sources : la release GitHub (si l'URL source est connue : liste des
   fichiers dans la modale, un clic importe) et un fichier local (bouton stylé, nom du fichier choisi, « Envoyer »).
   La case « Garder en test » s'applique aux deux. La modale se ferme sur un import réussi ; une erreur y reste.
6. **Template YAML** replié : nombre de lignes et « Modifier » ; déplié, l'éditeur actuel (enregistrer,
   réinitialiser avec confirmation, annuler).
7. **Réglages groupés** replié : nombre de réglages et « Ouvrir » ; textes accentués.
8. Tests : rendu statique des cartes (présence/absence selon l'état du jeu), logique pure extraite ; `pnpm gates` vert ;
   vérification visuelle (bureau et 390 px) sur la fiche DKC3.

## Tâches

- [x] Extraire l'onglet de `admin-game-editor.tsx` dans `admin-game-apworld-tab.tsx` (cartes, modales).
- [x] Modales test de génération, test YAML, remplacement ; confirmation de la dérogation.
- [x] Cartes repliées template et réglages groupés ; accents.
- [x] Tests, gates, vérification visuelle.

## Dev Agent Record

### Completion Notes

- `admin-game-apworld-tab.tsx` : `ApworldTab` (pile `gap-6`), `TabCard`, cartes « Version en ligne »,
  « Nouvelle version », « Template YAML » (repliée, éditeur gardé monté pour ne pas perdre un brouillon),
  « Valeurs des réglages groupés » (repliée, accents rétablis) ; « Fichiers du joueur » garde son composant, mis au
  même gabarit.
- Modales : `TestGenerationForm` (panneau ROM de la 44.6), `CandidateYamlTest` devenu contenu de modale (suivi du
  verdict dans la modale), `ReplaceApworldForm` (release GitHub listée dans la modale, fichier local au bouton stylé,
  « Garder en test »). « Autoriser malgré l'échec » passe par une confirmation ; « Retirer la dérogation » non.
- Les trois appels réseau du remplacement quittent le composant pour `admin-games-api.ts`
  (`fetchGithubApworldAssets`, `importApworldFromGithub`, `uploadApworldFile`, AC-API1) ; comportement inchangé, sauf
  qu'une release à un seul fichier est maintenant listée au lieu d'être importée d'office (l'admin voit ce qu'il importe).
- `admin-game-editor.tsx` perd ~560 lignes (section, éditeurs, bloc candidat, verdict, helpers devenus inutiles).
- `pnpm gates` vert (1093 tests, build). Vérifié dans le navigateur sur la fiche DKC3 : cartes espacées, modales
  « Tester la génération » (panneau ROM) et « Remplacer l'apworld », dépliage du template ; à 390 px rien ne déborde.

### File List

- `frontend/src/features/admin/admin-game-apworld-tab.tsx` (nouveau), `admin-game-apworld-tab.test.tsx` (nouveau)
- `frontend/src/features/admin/admin-game-editor.tsx`
- `frontend/src/features/admin/admin-games-api.ts`
- `frontend/src/features/admin/admin-game-test-files.ts`
- `frontend/src/features/admin/admin-game-required-files.tsx`
- `frontend/src/features/admin/apworld-candidate-status.tsx`
- `frontend/src/features/admin/candidate-yaml-test.tsx`, `candidate-yaml-test.test.tsx`

## Change Log

- 2026-10-16 : refonte de l'onglet APWorld (cartes, modales), front seulement.
