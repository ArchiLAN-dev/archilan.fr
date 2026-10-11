# Story 43.17: Annonces « cherche joueurs »

**Status:** review
**Epic:** 43 - Des amis qui servent à jouer
**Date:** 2026-10-02
**Dépend de:** 43.14

## Story

En tant que propriétaire d'une run perso en préparation,
je veux publier une annonce visible de tous les membres,
afin de compléter la partie au-delà de mes amis.

## Contexte

Équivalent du *Looking for Group* Xbox. Étend le réglage d'ouverture de 43.14 avec une valeur « Membres » et
une annonce. Le texte libre est un contenu utilisateur : le signalement existe (`ContentReport`,
`ReportCategory`, modération 30.13 / epic 39).

## Critères d'acceptation

1. Le réglage d'ouverture d'une run `draft` gagne la valeur « Membres », qui exige une annonce : message court
   (280 caractères max), jeux déjà choisis (automatique), places voulues, date prévue optionnelle.
2. Une page « Parties qui cherchent des joueurs » (connectés seulement, liée depuis `/communaute`) liste les
   annonces ouvertes, les plus récentes d'abord, avec les amis du viewer déjà inscrits mis en avant.
3. Rejoindre depuis l'annonce : même logique que 43.14 ; mêmes règles de places et de disparition.
4. Une annonce est signalable (`ContentReport`) ; un membre sanctionné ne peut ni publier ni rejoindre ; un
   blocage cache l'annonce dans les deux sens.
5. Une annonce sans arrivée depuis 14 jours expire (la run repasse « Sur invitation »), le propriétaire est
   prévenu.
6. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Domaine/Migration** : `pitch`, `plannedFor`, `listedAt` sur `Run` ; expiration.
- [x] **Application** : `OpenRunListingsQuery`, intégration du signalement (nouveau type de cible).
- [x] **Tâche planifiée** : expiration des annonces.
- [x] **Front** : formulaire d'annonce, page de liste, bouton signaler.
- [x] Tests et gates.

## Dev Notes

- **Domaine** (`Run`) : ouverture `members` (`OPEN_MEMBERS`), `listForMembers(pitch, seats, plannedFor, now)` (draft
  seulement ; message non vide, 280 caractères max), colonnes `pitch`, `planned_for`, `listed_at`, `last_arrival_at`
  (ajoutée pour l'expiration « sans arrivée » : une arrivée renouvelle l'annonce, la modifier non). `isListed()`,
  `recordArrival()`, `isListingExpired()` (14 jours sans arrivée depuis la mise en ligne), `expireListing()` (retour
  « Sur invitation »). Une annonce membres est aussi ouverte aux amis : elle apparaît dans « Parties de tes amis » (43.14).
  Migration `Version20261010170000`.
- **Application** :
  - `SetRunOpenness::set` reçoit `pitch` et `plannedFor` ; publier exige un compte non sanctionné (`Sanctioned`).
  - `JoinOpenRun` : sur une annonce, tout membre sans blocage dans un sens ou l'autre avec le propriétaire, non
    sanctionné ; mêmes règles de places que 43.14 ; enregistre l'arrivée.
  - `OpenRunListingsQuery` (via `RunListingsQueryInterface` / `DbalRunListingsQuery`) : annonces draft, hors les
    siennes, celles où l'on est déjà, et tout blocage ; les pleines filtrées ; propriétaire sans carte listable
    (suspendu, banni) écarté ; jeux choisis (noms) ; amis du viewer déjà inscrits (`friendsIn`) et `isOwnerFriend`.
- **Sanction** : un compte suspendu ou banni ne passe déjà plus l'authentification (401) ; les contrôles des commandes
  restent en défense en profondeur, et l'annonce d'un propriétaire suspendu disparaît de la liste.
- **Signalement** : nouvelle cible `ContentReport::TARGET_RUN_LISTING` (`run_listing`), `ReportRunListingService`
  (catégorie `other`, problème au choix, une fois par membre, pas sa propre annonce), route
  `POST /api/v1/community/run-listings/{runId}/report`. La file de modération affiche l'annonce (`runListing` :
  titre, message, auteur) et propose le filtre « Annonces ». Pas d'action « retirer l'annonce » dédiée dans cette
  story : la modération résout le signalement et peut sanctionner l'auteur, ce qui masque l'annonce.
- **Expiration** : `ExpireRunListingsMessage` toutes les heures (`10 * * * *`), `ExpireRunListingsHandler` : la run
  repasse « Sur invitation », puis notification `run_listing_expired` (cloche) au propriétaire.
- **Routes** : `PUT /api/v1/runs/{id}/openness` (`openness: members`, `pitch`, `plannedFor`, `seatsWanted`),
  `GET /api/v1/run-listings`, `POST /api/v1/runs/{id}/join-open` (403 si sanctionné). La fiche de run expose
  `pitch`, `plannedFor`, `listedAt`.
- **Front** : réglage « Tous les membres (annonce) » (message avec compteur, places, date prévue) ; page
  `/communaute/parties` (connectés, `noindex`), lien depuis `/communaute` ; carte d'annonce (propriétaire, badge Ami,
  places, jeux, date, amis déjà inscrits, Rejoindre, Signaler) ; cloche `run_listing_expired` ; modération.
- **Tests** : `RunListingsTest` (5 : visibilité + jointure + amis mis en avant, message et sanction, blocage et
  places, expiration, signalement + file de modération), `run-listings.test.tsx` (4).
- **A déployer** : la migration `Version20261010170000`.
