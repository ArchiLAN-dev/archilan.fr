# Story 53.10: « Mon event » en tableau de bord

**Status:** review
**Epic:** 53 - Mon event (espace inscrit)
**Date:** 2026-11-17

## Story

En tant qu'inscrit à un événement,
je veux un espace « Mon event » qui montre d'abord ce qui compte maintenant (le compte à rebours, ce qu'il me reste
à faire, ma partie) et range le reste proprement,
afin de trouver en un coup d'œil ce que j'ai à faire au lieu de parcourir une longue colonne de blocs.

## Contexte

Page `/evenements/{slug}/mon-event` (`features/registrant-hub/registrant-hub.tsx`, `RegistrantHubView`). Retour de
Jean (2026-11-17) : la page « n'est pas du tout pensée niveau UI/UX ». Constat sur l'event de démo `demo423` :

- une seule colonne : tout a le même poids, le compte à rebours (« J-76 ») est une petite pastille, ce que l'orga
  attend (questionnaire) est noyé au milieu des outils, la moitié droite d'un écran large reste vide ;
- des cartes dans des cartes : « Les outils de l'event » enveloppe des cartes qui ont chacune leur bord, leur largeur
  (Minimapperz est plus étroite) et leur taille de titre (« Quand es-tu là ? » et « Ton stream » plus gros que les
  outils) ;
- des doublons : « Ma partie » est un outil et un lien de « Mon inscription » ; « C'est quoi ? » se répète sous
  chaque carte ;
- la checklist prend un bloc entier pour dire « Tu es prêt ! ».

**Choix de Jean (2026-11-17) : un tableau de bord** (préféré aux onglets et à une liste resserrée).

Les cartes réutilisées viennent d'autres features et servent aussi ailleurs : `RegistrationPresenceCard`
(`features/presence`), `RegistrationBroadcastConsentCard` et `MediaPublishCard` (`features/streaming`),
`MinimapperzCodeCard` (`features/wallet`), `PasseurNoticeCard` (`features/passeur`), `EventSurveyBanner`,
`BlindTestJoinButton`, `HubSessionCard`, `HubPellesCard`. Leur comportement ne change pas ; seul leur habillage dans
l'espace inscrit est unifié.

Les moments de la story 53.6 restent : avant, pendant, après (`eventPhase`, `hubSectionOrder`).

## Critères d'acceptation

1. **En-tête** : titre de l'event, dates, lieu et état de l'inscription sur un bloc compact ; le moment de l'event
   (« J-76 », « En cours », « Terminé », `eventPhaseLabel`) mis en avant en grand, pas en petite pastille. Le lien
   « Page de l'événement » reste. Une inscription à confirmer garde son appel « Confirmer mon inscription », en tête.
2. **« À faire maintenant »** : un bandeau sous l'en-tête regroupe ce qui attend l'inscrit : les étapes restantes de
   la checklist (53.2) et un questionnaire ouvert de l'orga (`EventSurveyBanner`). Il n'apparaît que s'il y a
   quelque chose à faire ; une checklist complète ne prend plus de bloc (« Tu es prêt ! » devient une mention
   discrète, la liste reste consultable).
3. **Deux colonnes sur grand écran** (`lg` et plus) : à gauche le principal (ma partie, le Passeur pendant une
   partie, les infos pratiques), à droite les petits outils (code Minimapperz, blind test, pelles, présence, stream
   et diffusion, guides, liens de l'inscription). Une colonne sur téléphone, dans le même ordre d'importance. Aucun
   défilement horizontal à 360 px.
4. **Une seule épaisseur de cartes** : chaque outil est une carte du tableau de bord, même bord, même fond, même
   largeur que sa colonne, même taille de titre ; plus de carte dans une carte. Les cartes partagées gardent leur
   rendu actuel sur les autres pages.
5. **Plus de doublons** : « Ma partie » n'apparaît qu'une fois (la carte de la partie porte le lien vers la page de
   session) ; « Récapitulatif de mon inscription » est un lien de l'en-tête ou de la colonne de droite ; les liens
   « C'est quoi ? » restent mais en discret, sans répéter un bloc entier.
6. **Moments (53.6)** : pendant l'event, la partie et le Passeur passent en tête de la colonne principale, la
   checklist ne s'affiche plus que repliée dans le bandeau s'il reste une étape ; après l'event, « Revivre l'event »
   prend la place de la partie, le bandeau ne montre que le questionnaire de fin s'il est ouvert, la présence et le
   stream disparaissent (comme aujourd'hui).
7. **États de chargement et d'erreur** : le squelette suit la nouvelle grille ; une section en erreur ou vide ne
   laisse pas de trou (comme `RegistrantHubSection` aujourd'hui).
8. Tests : la vue (`RegistrantHubView`) aux trois moments (bandeau présent ou absent, ordre des colonnes, pas de
   doublon « Ma partie ») ; les tests existants de l'espace inscrit mis à jour.
9. Gates : `pnpm gates` vert (frontend seul, aucune API ni migration).

## Tasks

- [x] Task 1 (AC 1) - en-tête compact, moment en grand.
- [x] Task 2 (AC 2, 6) - bandeau « À faire maintenant » (checklist restante, questionnaire) ; checklist complète en
  mention discrète.
- [x] Task 3 (AC 3, 4, 5) - grille deux colonnes, une seule épaisseur de cartes (habillage commun, option
  d'intégration sur les cartes partagées si besoin), doublons retirés.
- [x] Task 4 (AC 6, 7) - ordre par moment, squelette.
- [x] Task 5 (AC 8, 9) - tests, gates, vérification réelle sur `demo423` (bureau et 360 px).

## Dev Notes

- Frontend seul. Branche `feature/epic-53-story-10-mon-event-tableau-de-bord` depuis `develop`.
- `hubSectionOrder` (53.6) décrit l'ordre par moment ; il évolue en deux listes (colonne principale, colonne
  latérale) plus le bandeau.
- Vérification locale : event `demo423` (inscription confirmée de Jean), cookie de session signé localement.

## Dev Agent Record

### File List

- `frontend/src/features/registrant-hub/registrant-hub.tsx`
- `frontend/src/features/registrant-hub/hub-tools.tsx`
- `frontend/src/features/registrant-hub/hub-sections.ts`
- `frontend/src/features/registrant-hub/registrant-checklist.tsx`
- `frontend/src/features/registrant-hub/hub-sections.test.tsx`
- `frontend/src/features/registrant-hub/registrant-hub.test.tsx`
- `frontend/src/features/registrant-hub/registrant-checklist.test.tsx`

### Completion Notes

- En-tête : dates, lieu et inscription confirmée sur une ligne ; le moment (J-76, En cours, Terminé) en grand à
  droite du titre (sous le titre sur téléphone). L'inscription à confirmer garde son bandeau.
- Bandeau « À faire maintenant » (`role="region"`) : étapes restantes (pliées pendant l'event) et questionnaires
  ouverts (`#sondages`) ; absent quand rien n'attend. Liste complète : « Tu es prêt ! » dans « Mon inscription ».
- Colonne principale (`hubMainSections`) : avant = partie, présence et stream (`HubParticipation`), infos
  pratiques ; pendant = partie, infos pratiques, présence et stream ; après = Revivre l'event. Colonne latérale
  (`#outils`) : blind test, code Minimapperz, pelles, Mon inscription, guides (titres seuls, résumé en infobulle).
- Une seule épaisseur de cartes : `HubTile` uniformise le cadre extérieur des cartes partagées (bord, fond,
  padding, titre en text-sm) par sélecteurs descendants, sans toucher à leur rendu sur leurs autres pages.
- Doublon « Ma partie » retiré (le lien vit sur la carte de la partie) ; le squelette suit la grille.
- Vérifié en local sur `demo423` : 1440 px, colonnes de 528 et 580 px, page de 1175 px de haut (2523 avant) ;
  360 px, une colonne dans l'ordre d'importance, aucun débordement. `pnpm gates` vert.
