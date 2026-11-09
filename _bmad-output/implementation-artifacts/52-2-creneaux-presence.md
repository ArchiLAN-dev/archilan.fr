# Story 52.2 - Créneaux de présence sur l'inscription

**Status:** ready-for-dev
**Epic:** 52 - Le Passeur (`_bmad-output/planning-artifacts/epics/epic-52-passeur.md`)
**Date:** 2026-11-09
**Branche:** `feature/epic-52-story-2-creneaux-presence` (depuis `develop`)
**Dépend de:** rien (s'inspire de 51.5 pour l'étape du parcours).

## Story

En tant que joueur inscrit à un événement,
je veux indiquer quand j'arrive et quand je repars,
afin que l'orga s'organise et que le Passeur sache quand je suis absent.

## Contexte (cartographie du 2026-11-09)

- Les sondages (epic 51) recueillent des réponses libres : une question « Quand viens-tu ? » n'est pas exploitable
  par une machine. Il faut une donnée structurée, portée par l'inscription.
- Parcours d'inscription : `frontend/src/app/(public)/evenements/[eventSlug]/inscription/`, stepper
  `frontend/src/features/events/registration-stepper.tsx` (étape « Questions » optionnelle depuis 51.5).
- Inscription : contexte `Registrations`, entité `Registration` ; fiche admin d'une inscription
  `frontend/src/features/admin/.../admin-registration-detail.tsx`.

## Décisions

1. **Une ou plusieurs plages** (arrivée, départ) par inscription, en date et heure (Europe/Paris à l'affichage,
   UTC en base). Une plage est comprise dans les dates de l'événement ; les plages ne se chevauchent pas.
2. **Sans plage : présent tout l'événement.** Le Passeur ne considère jamais absent un joueur sans plage.
3. **Saisie facultative**, pas une étape bloquante : un encart dans le récapitulatif de l'inscription et sur la page
   de l'événement (« Dis-nous quand tu es là »), modifiable jusqu'à la fin de l'événement.
4. **L'admin** voit et modifie les plages depuis la fiche d'inscription.
5. **Vue admin « Qui est là quand »** : frise par jour de l'événement, une ligne par inscrit confirmé, plages en
   couleur, compteur de présents par demi-journée.
6. Nouvelle table `registration_presence_window` (id, registration_id, starts_at, ends_at), migration réversible.
   Pas de colonne JSON : la requête « absents à l'instant T » doit rester simple.

## Critères d'acceptation

1. Le joueur ajoute, modifie, supprime ses plages ; refus clair hors des dates de l'événement ou en cas de
   chevauchement (422 avec code).
2. L'admin fait de même depuis la fiche d'inscription.
3. La frise admin affiche les plages et le compteur par demi-journée.
4. Une requête d'Application `PresenceWindowQueryInterface::isOutsideWindows(registrationId, now)` (ou par slot)
   répond « hors créneau » seulement si au moins une plage existe et qu'aucune ne contient `now`.

## Tests et gates

5. Unitaires : règles de plage (bornes, chevauchement) dans le domaine.
6. Fonctionnels : CRUD joueur et admin, droits (un joueur ne touche pas aux plages d'un autre), requête hors
   créneau (sans plage, dans une plage, entre deux plages).
7. Front : encart, formulaire, frise.
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1, 4) - Domaine, migration, commandes et requête, endpoints joueur.
- [ ] **Task 2** (AC 2, 3) - Endpoints admin et frise.
- [ ] **Task 3** (AC 1) - Encart et formulaire côté joueur.
- [ ] **Task 4** (AC 8) - Gates verts, Dev Agent Record.
