# Story 3.17 - Hub d'un événement (admin)

**Status:** ready-for-dev
**Epic:** 3 - Event Lifecycle Backoffice (`_bmad-output/planning-artifacts/epics/epic-3-event-lifecycle-backoffice.md`)
**Date:** 2026-11-10
**Branche:** `feature/epic-3-story-17-hub-evenement` (depuis `develop`)
**Dépend de:** rien. Front seul, aucune route API nouvelle.

## Story

En tant qu'orga,
je veux une page par événement qui regroupe tout ce que je peux y faire, et une liste des événements qui reste lisible,
afin de trouver une action sans parcourir une rangée de boutons qui s'allonge à chaque nouvelle fonctionnalité.

## Contexte (cartographie du 2026-11-10)

- `/admin/evenements` (`features/admin/admin-event-dashboard.tsx`) : chaque ligne porte jusqu'à 13 commandes au même
  niveau :
  - Actions : Modifier, Inscrits, Pelles, Partenaires, Sondages, Présences, Mettre en avant / Retirer de l'accueil,
    Run, les transitions de statut (Publier, Dépublier, Démarrer, Revenir publié, Terminer, Rouvrir), Config. accès ;
  - colonnes Jeux (Configurer / Activée) et Récap (Attacher).
- Il n'existe pas de page `/admin/evenements/{id}`. Les sous-pages existent déjà : `modifier`, `jeux`, `inscriptions`,
  `pelles`, `partenaires`, `sondages`, `presences`, `session` (et `session/{sessionId}`, où vivent le journal du
  Passeur, les départs et la carte des dépendances). Chacune a son propre lien de retour vers la liste.
- Dialogues existants dans le tableau de bord : confirmation de transition (`transitionConfirmation`), accès privé,
  récap / VOD. Appels API dans `features/admin/admin-events-api.ts` (`updateAdminEventStatus`,
  `updateAdminEventPrivateAccess`, `saveAdminEventRecap`, `featureAdminEventOnHome`, `fetchAdminEventForEdit`).
- Les épics 45 à 52 ont chacune ajouté un bouton à la ligne : le problème grossit avec chaque fonctionnalité.

## Décisions (option « hub » validée par Jean le 2026-11-10)

1. **Une page hub** `/admin/evenements/{id}` : en-tête de l'événement (titre, statut, dates, visibilité, remplissage,
   mis en avant), transitions de statut, puis l'accès à chaque section.
2. **Sections du hub**, dans l'ordre de vie d'un événement :
   - **Préparation** : Informations (`modifier`), Jeux (`jeux`), Partenaires (`partenaires`), Accès privé (dialogue).
   - **Joueurs** : Inscriptions (`inscriptions`), Présences (`presences`), Sondages (`sondages`), Pelles (`pelles`).
   - **Jour J** : Run et sessions (`session`).
   - **Après** : Récap / VOD (dialogue), Mise en avant sur l'accueil.
   Chaque entrée donne un état court quand la liste des événements le connaît déjà (ex. « Sélection activée »,
   « 23/40 inscrits », « Récap attaché ») ; aucune requête nouvelle pour ça.
3. **Navigation commune aux sous-pages** : un `layout.tsx` sous `[eventId]` affiche le titre de l'événement, un lien
   « Événements » et la navigation des sections (onglets défilants sur mobile, barre latérale sur desktop). Les liens
   de retour propres à chaque sous-page disparaissent au profit de ce layout. Les URL existantes ne changent pas.
4. **Liste allégée** : chaque ligne garde titre (lien vers le hub), statut, début, remplissage, visibilité, et
   - **une action principale**, la prochaine étape du cycle : Publier (brouillon), Démarrer (publié), Terminer (en
     cours), Attacher le récap (terminé sans récap) ; rien pour un terminé avec récap ;
   - un menu « ⋯ » avec le reste : Ouvrir le hub, les autres transitions (Dépublier, Revenir publié, Rouvrir), Mettre
     en avant / Retirer de l'accueil, Accès privé.
   Les colonnes Jeux et Récap quittent la liste (elles vivent dans le hub).
5. **Mêmes confirmations** qu'aujourd'hui pour les transitions et mêmes dialogues pour l'accès privé et le récap,
   partagés entre la liste et le hub (extraits du tableau de bord, pas recopiés).
6. **Pas de nouvelle route API.** Le hub lit l'événement avec ce que la liste lit déjà (`fetchAdminEvents` filtré, ou
   l'appel d'édition s'il suffit) ; si un champ manque, le signaler plutôt que d'ajouter une route.

## Critères d'acceptation

1. `/admin/evenements/{id}` affiche l'en-tête et les quatre sections ; chaque entrée mène à la page ou au dialogue
   existant. Un id inconnu donne la page « introuvable » habituelle de l'admin.
2. Les sous-pages `modifier`, `jeux`, `inscriptions`, `pelles`, `partenaires`, `sondages`, `presences`, `session`
   (et ses pages enfants) partagent la navigation du hub ; la section courante est marquée (`aria-current`).
3. Dans la liste, une ligne n'a plus qu'une action principale et un menu « ⋯ » ; le titre ouvre le hub. Le menu est
   accessible au clavier (ouverture, flèches, Échap, retour du focus) et se ferme au clic extérieur.
4. L'action principale suit le statut comme décrit en décision 4 ; les transitions demandent la même confirmation
   qu'avant.
5. Mobile (largeur 375 px) : liste en cartes sans défilement horizontal, menu « ⋯ » utilisable, navigation du hub en
   onglets défilants.
6. Aucune fonctionnalité perdue : chaque commande de la liste actuelle reste joignable en deux clics au plus depuis
   `/admin/evenements`.

## Tests et gates

7. Jest :
   - action principale selon le statut (les quatre statuts, terminé avec et sans récap) ;
   - contenu du menu « ⋯ » selon le statut et la visibilité ;
   - hub : sections et liens attendus, état court des entrées, id inconnu ;
   - navigation du layout : section courante marquée.
8. `pnpm gates` vert. Vérification visuelle sur 127.0.0.1 (desktop et 375 px) avant la PR.

## Tasks / Subtasks

- [ ] Task 1 : extraire du tableau de bord les dialogues (transition, accès privé, récap) et la logique de statut
  (`transitionActions`, `transitionConfirmation`, action principale) dans des modules partagés (AC 4, 5)
- [ ] Task 2 : page hub `/admin/evenements/[eventId]/page.tsx` et ses sections (AC 1)
- [ ] Task 3 : `layout.tsx` de navigation sous `[eventId]`, retrait des liens de retour propres aux sous-pages (AC 2)
- [ ] Task 4 : liste allégée, action principale, menu « ⋯ » accessible (AC 3, 4, 5, 6)
- [ ] Task 5 : tests Jest, gates, vérification visuelle (AC 7, 8)

## Dev Agent Record
