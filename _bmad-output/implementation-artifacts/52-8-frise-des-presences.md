# Story 52.8 - Une frise des présences lisible (admin)

**Status:** review
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-52-story-8-frise-des-presences` (depuis `feature/epic-3-story-17-hub-evenement`, PR #1058)
**Dépend de:** 52.2 (créneaux), 3.17 (navigation de l'événement). Front seul, page admin seulement.

## Story

En tant qu'orga,
je veux lire d'un coup d'œil qui est là quand pendant l'événement, et qui n'a rien déclaré,
afin de préparer l'accueil, les sessions et les relances sans déchiffrer la frise.

## Contexte (cartographie du 2026-11-10)

- Page `/admin/evenements/{id}/presences` (`features/presence/admin-presence-timeline.tsx`, story 52.2). Données :
  `GET /admin/events/{id}/presence-timeline` (`eventStartsAt`, `eventEndsAt`, inscrits confirmés avec `displayName`,
  `registrationId` et leurs plages). Aucune donnée nouvelle n'est nécessaire.
- Défauts constatés :
  - un inscrit sans plage s'affiche en barre verte pleine, comme s'il avait déclaré tout l'événement ;
  - aucune grille, aucune heure, aucune séparation de jour : on ne lit pas « arrive samedi vers 14 h » ;
  - le nombre de présents par demi-journée est un petit chiffre sous un libellé minuscule ;
  - pas de résumé, pas de légende, pas de tri (ordre de la base) ;
  - `aria-label` des barres en ISO brut ;
  - sur mobile, une frise de 640 px qui défile de côté.

## Décisions

1. **Résumé en tête** : nombre d'inscrits, de déclarés, de non-déclarés, et le creux (la demi-journée la moins
   remplie, avec son nombre de présents).
2. **Axe** : un bandeau par jour (« sam. 5 »), les demi-journées dessous, une graduation toutes les 6 h (heure de
   Paris), une grille verticale sur toute la hauteur, un repère « maintenant » quand l'événement est en cours.
3. **Présents par demi-journée** : un histogramme (barre proportionnelle au nombre d'inscrits, nombre affiché), coloré
   selon la part présente : au moins 75 % normal, au moins 50 % attention, en dessous alerte.
4. **Lignes** : initiales et pseudo (lien vers l'inscription), barres avec leurs heures écrites (« sam. 14 h → dim.
   12 h ») et un libellé accessible en mots. **Non-déclaré** : barre hachurée grise « pas déclaré », jamais confondue
   avec une présence déclarée.
5. **Ordre et filtre** : déclarés d'abord, par heure d'arrivée, puis non-déclarés par nom ; filtre Tous / Déclarés /
   Non déclarés.
6. **Mobile** (sous `md`) : une carte par demi-journée (« sam. 5 matin, 18 / 24 ») avec les absents nommés et le
   nombre de non-déclarés, à la place de la frise.
7. La règle de comptage ne change pas : sans plage, l'inscrit compte comme présent (52.2) ; la page le dit.

## Critères d'acceptation

1. Le résumé donne inscrits, déclarés, non-déclarés et le creux.
2. Un non-déclaré se distingue visuellement et textuellement (« pas déclaré ») d'un présent tout l'événement.
3. Les jours, les demi-journées et les heures se lisent sur l'axe ; la grille traverse toutes les lignes.
4. Les présents par demi-journée s'affichent en histogramme avec leur nombre.
5. L'ordre et le filtre suivent la décision 5.
6. À 375 px, aucune frise ne défile de côté : la vue par demi-journée la remplace.
7. Aucune date ISO dans le texte affiché ni dans les libellés accessibles.

## Tests et gates

8. Jest : résumé et creux, ordre et filtre, non-déclaré hachuré et libellé, heures écrites dans les barres,
   graduations de 6 h, vue mobile par demi-journée (absents nommés), libellés accessibles sans ISO.
9. `pnpm gates` vert.

## Tasks / Subtasks

- [x] Task 1 : calculs purs (résumé, creux, ordre, filtre, graduations, libellés d'heures) (AC 1, 3, 5, 7)
- [x] Task 2 : vue desktop (axe, grille, histogramme, lignes, repère maintenant) (AC 2, 3, 4)
- [x] Task 3 : vue mobile par demi-journée (AC 6)
- [x] Task 4 : tests et gates (AC 8, 9)

## Dev Agent Record

### Livré

- `features/presence/admin-presence-timeline.tsx` réécrit. Calculs purs exportés : `presenceSummary` (creux compris),
  `orderedRegistrants` (filtre), `hourTicks`, `dayBands`, `absentDuring`, `shortMoment` ; `Segment` gagne `day` et
  `half`. La règle de comptage de 52.2 est inchangée.
- Desktop : tuiles de résumé, filtre (`aria-pressed`), bandeau des jours, histogramme coloré par part présente,
  graduations de 6 h, grille sur toute la hauteur (minuit plein, midi pointillé), repère « maintenant » (lu une fois
  au montage), barres avec leurs heures et un libellé en mots (`describeWindow`), non-déclarés hachurés « pas
  déclaré » en bas, légende.
- Mobile (sous `md`) : une carte par demi-journée, jauge, absents nommés, nombre de non-déclarés.

### Tests et gates

- `presence.test.tsx` : 7 tests 52.8 en plus, celui de 52.2 adapté (« pas déclaré »).
- Front : typecheck, lint, 1516 tests, build.
- Vérification visuelle à faire par Jean (pas de serveur de dev lancé par l'agent).
