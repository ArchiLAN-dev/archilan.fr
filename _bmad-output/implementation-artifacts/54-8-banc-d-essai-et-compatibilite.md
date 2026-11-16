# Story 54.8 - Banc d'essai et compatibilité

**Status:** ready-for-dev
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

- [ ] **Task 1** (AC 1) - Mode essai du tracker, accès admin aux versions non servies.
- [ ] **Task 2** (AC 2, 3) - Endpoint de rapport, stockage, pastilles, confirmation.
- [ ] **Task 3** (AC 4) - Campagne sur 10 jeux, compléments du shim, `deferred-work.md`.
- [ ] **Task 4** (AC 8) - Gates.
