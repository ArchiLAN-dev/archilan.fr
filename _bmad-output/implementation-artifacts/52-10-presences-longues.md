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

1. **Événement court** (4 jours ou moins) : rien ne change, la frise de 52.8 couvre tout l'événement.
2. **Événement long** (plus de 4 jours) :
   - **Calendrier** en tête : une ligne par semaine (lundi à dimanche, heure de Paris), une case par jour avec sa date
     et le nombre de présents ce jour-là (présent = une plage qui touche le jour, ou rien de déclaré), colorée comme
     l'histogramme de 52.8 (au moins 75 %, au moins 50 %, en dessous). Les jours hors de l'événement sont vides.
   - **Une semaine à la fois** dans la frise : la semaine se choisit en cliquant sa ligne du calendrier, ou par
     « semaine précédente / suivante ». Par défaut, la semaine en cours si l'événement a commencé, sinon la première.
   - La frise de la semaine garde tout 52.8 (bandeau des jours, histogramme par demi-journée, graduations, lignes,
     repère « maintenant »), bornée à la semaine. Une barre qui déborde de la semaine est coupée au bord et garde ses
     vraies heures dans son texte. Un inscrit déclaré mais absent toute la semaine a sa ligne marquée « pas là cette
     semaine ».
   - Sur mobile : le calendrier (sept colonnes étroites), puis les cartes par demi-journée de la semaine choisie.
3. Le résumé (inscrits, déclarés, non déclarés, creux) reste calculé sur tout l'événement.

## Critères d'acceptation

1. Un événement de 4 jours ou moins s'affiche comme avant (pas de calendrier, pas de navigation).
2. Un événement plus long montre le calendrier : semaines du lundi au dimanche, date et nombre de présents par jour,
   cases vides hors de l'événement, couleur selon la part présente.
3. La frise ne montre qu'une semaine ; la semaine choisie est marquée dans le calendrier (`aria-pressed`) ; précédente
   et suivante sont désactivées aux bornes.
4. Semaine par défaut : celle qui contient « maintenant » pendant l'événement, sinon la première.
5. Barres coupées aux bords de la semaine, texte aux vraies heures ; ligne « pas là cette semaine » si besoin.
6. Les jours suivent l'heure de Paris, changement d'heure compris (une case par jour civil, pas de doublon).
7. Tests : découpe en jours et en semaines (y compris passage à l'heure d'hiver), calendrier, semaine par défaut,
   bornage de la frise, événement court inchangé.
8. `pnpm gates` vert.

## Tâches

- [x] Découpe : jours de Paris, semaines du lundi, semaine contenant un instant.
- [x] Calendrier des présences.
- [x] Frise bornée à une plage (au lieu de l'événement entier), navigation par semaine.
- [x] Mobile : cartes de la semaine.
- [x] Tests, gates, vérification visuelle sur la démo 4.23.

## Dev Agent Record

- `admin-presence-timeline.tsx` : `halfDaysBetween`, `parisDays` (coupes à minuit de Paris, heure par heure, donc
  une case par date au changement d'heure), `parisWeeks` (lundi à dimanche, bornées à l'événement), `weekIndexAt`,
  `LONG_EVENT_MS` (4 jours). `PresenceCalendar` : une ligne-bouton par semaine (`aria-pressed`), sept cases
  (`data-day="in"` / `"out"`), date, pastille de couleur et nombre de présents ; largeur bornée (`max-w-2xl`).
- La frise prend une plage (`range`) : l'événement entier, ou la semaine choisie. Les barres hors plage ne sont pas
  dessinées, celles à cheval sont coupées par le bornage existant ; « pas là cette semaine » pour un déclaré absent
  de la plage. Le résumé reste sur tout l'événement ; les cartes mobiles suivent la semaine.
- Vérifié en local sur 127.0.0.1 (démo 4.23, un mois) : calendrier de 5 semaines, frise d'une semaine lisible.
- Tests : `presence.test.tsx` (+4). Front : typecheck, lint, 1529 tests, build verts.
