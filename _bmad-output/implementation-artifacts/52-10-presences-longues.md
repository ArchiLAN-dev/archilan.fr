# Story 52.10 - Les présences d'un événement de plusieurs semaines (admin)

**Status:** review
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-52-story-10-presences-longues` (depuis `feature/epic-3-story-18-barre-evenement`)
**Dépend de:** 52.8 (frise lisible), 3.18 (barre d'événement, pleine largeur). Front seul, aucune donnée nouvelle.

## Story

En tant qu'orga d'un événement long (ArchiWAN de plusieurs semaines),
je veux voir les présences jour par jour sur tout l'événement, puis le détail d'une semaine,
afin de lire qui est là quand sans une frise de soixante demi-journées écrasées.

## Contexte

Retour de Jean le 2026-11-10 : « la présence sur des events de plusieurs semaines c'est illisible ». La frise de 52.8
met toutes les demi-journées de l'événement sur la largeur de l'écran : sur la démo 4.23 (un mois), 56 colonnes de
moins de 25 px, des nombres et libellés tronqués, des barres sans texte lisible.

## Décisions

Première version (calendrier par semaine + frise d'une semaine) jugée encore « imbuvable » par Jean le 2026-11-10 ;
remplacée, sur son choix parmi trois maquettes, par une **grille planning** :

1. Une ligne par inscrit (lien vers son inscription), une colonne par **jour** sur tout l'événement quand il dure plus
   de 4 jours, par **demi-journée** sinon. Plus de frise à barres ni d'heures écrites.
2. Case pleine : présent toute la colonne (à une demi-heure près). Case en partie remplie : présent une partie, la
   partie remplie à sa place (arrivée l'après-midi : la droite de la case). Case hachurée : rien déclaré, compté
   présent. Case vide : absent. Les heures exactes sont dans le titre de la case (survol) et sur la fiche.
3. En tête de chaque colonne : le jour (ou la demi-journée) et le nombre de présents, coloré selon la part présente.
   Un trait plus épais sépare les semaines (chaque lundi) ou les jours (demi-journées). La colonne du jour est
   marquée.
4. **Semaine par semaine** (demande de Jean, « on utilisera rarement un mensuel ») : un événement de plus de 4 jours
   s'ouvre sur la semaine en cours (la première avant l'événement), une case par demi-journée, avec « semaine
   précédente / suivante ». « Tout l'événement » passe à la grille entière, une case par jour, et revient.
5. Le pseudo reste collé à gauche quand la grille défile ; mêmes résumé, filtre Tous / Déclarés / Non déclarés et
   tri que 52.8, sur toutes les tailles d'écran (la grille défile de côté sur mobile, les cartes de 52.8 partent).

## Critères d'acceptation

1. Un événement de plus de 4 jours a une colonne par jour civil de Paris (changement d'heure compris, pas de
   doublon) ; 4 jours ou moins, une colonne par demi-journée.
2. Chaque case dit plein, une partie, rien déclaré ou absent, avec les heures au survol et un libellé accessible.
3. Les colonnes portent le nombre de présents ; les semaines (ou les jours) sont séparées ; le jour courant marqué.
4. Le filtre et le tri de 52.8 s'appliquent ; « Aucun inscrit dans ce filtre » si vide.
5. Tests : colonnes (jours et demi-journées), cases, rendu complet d'un événement long et court.
6. `pnpm gates` vert.

## Tâches

- [x] Découpe en jours de Paris et colonnes de la grille.
- [x] Cases (plein, partie, rien déclaré, absent).
- [x] Grille avec en-têtes, comptes, séparateurs, colonne collée.
- [x] Tests, gates, vérification visuelle sur la démo 4.23 avec des données de démo locales (20 inscrits).

## Dev Agent Record

- `admin-presence-timeline.tsx` : `parisWeeks` / `weekIndexAt` (semaines du lundi, semaine d'un instant), `GridButton`,
  `planningColumns(start, end, "day" | "half")` (jours ou demi-journées, `group` aux lundis ou aux matins),
  `planningCell` (union des plages qui touchent la colonne, en fraction ; plein à une demi-heure près), `PlanningGrid`
  (tableau, pseudo `sticky`, comptes colorés, `data-today`), `PlanningCell` (`data-cell="full|part"`). Retirés : la
  frise à barres, le calendrier et la navigation par semaine de la première version, `hourTicks`, `dayBands`,
  `absentDuring`, `shortMoment`, les cartes mobiles.
- La page a son propre padding (`px-4 pt-6 md:px-6`) depuis la barre de 3.18.
- Vérifié en local sur la démo 4.23 (un mois, 21 inscrits) : grille lisible d'un coup, sur toute la largeur.
- Tests : `presence.test.tsx` réécrit pour la grille. Front : typecheck, lint, tests, build verts.
