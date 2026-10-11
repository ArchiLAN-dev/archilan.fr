# Story 43.22: Une carte « Faire venir des joueurs » plus lisible

**Status:** review
**Epic:** 43 - Amis
**Date:** 2026-10-11

## Story

En tant que créateur d'une partie,
je veux comprendre d'un coup d'oeil qui peut rejoindre ma partie et régler son ouverture sans chercher,
afin de recruter des joueurs sans relire toute la carte.

## Contexte

Retour de Jean (2026-10-11) après la story 43.20. La carte reste longue et plate : l'en-tête mêle l'état et l'action
(« Annonce en ligne · masquer les réglages »), les champs s'empilent un par un, l'état se répète sous le formulaire,
et les titres en capitales écrasent la hiérarchie.

## Critères d'acceptation

1. **En-tête** : le titre, l'état en pastille (Sur invitation, Ouverte à tes amis, Annonce en ligne, avec les places),
   un bouton « Réglages et lien » qui ouvre et ferme, et « Inviter des amis ».
2. **Annonce en ligne, carte repliée** : le message tel que les membres le lisent, la date prévue et « Voir dans les
   annonces ».
3. **Réglages** : « Qui peut rejoindre ? » en titre normal ; sous le message, où l'annonce paraît et quand elle expire ;
   date prévue et places côte à côte ; l'état ou ce qu'il reste à faire, et le bouton d'enregistrement, sur une même
   ligne en bas du formulaire.
4. **Lien d'invitation** : titre normal, « Pour quelqu'un qui n'est pas dans tes amis » à côté.
5. Rien ne change côté API. Gates verts ; tests (pastille, aperçu de l'annonce, carte repliée).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2) - `RunRecruitPanel` prend l'état (`RecruitState`), `RecruitPill`, aperçu de l'annonce ; tests.
- [x] **Task 2** (AC 3, 4) - Mise en page du formulaire (`OpennessStatus`) et du lien.
- [x] **Task 3** (AC 5) - Gates ; vérification en local.

## Dev Agent Record

- `run-recruit-panel.tsx` : `RecruitState`, `RecruitPill` (couleur par ouverture, point vert pour une annonce),
  `ListingPreview` ; le bouton de dépli quitte le titre.
- `friends-open-runs.tsx` : `OpennessStatus` en pied de formulaire avec le bouton ; date et places en grille ; aide
  sous le message.
- `invite-link-panel.tsx` : titre et destinataire sur une ligne.
- Vérifié sur une page d'aperçu locale non commitée (l'écran réel demande d'être connecté).
