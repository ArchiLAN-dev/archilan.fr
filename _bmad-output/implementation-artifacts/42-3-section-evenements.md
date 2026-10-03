# Story 42.3: Section Événements des statistiques admin

**Status:** review
**Epic:** 42 - Statistiques admin
**Date:** 2026-10-03

## Story

En tant qu'admin,
je veux voir les inscriptions, les annulations, le remplissage des événements et les recettes sur la période choisie,
afin de savoir si les événements attirent et ce qu'ils rapportent à l'association.

## Contexte

Troisième et dernière section de la page `/admin/statistiques` (stories 42.1, 42.2), sur le même socle
(`StatsPeriod`, `DbalStatsReader`, chiffres clés avec écart, `TrendChart`). Les données :

- `registration` : une inscription par membre et par événement (`created_at`), annulée en passant au statut
  `cancelled` ; la date d'annulation n'est pas gardée à part, c'est le `updated_at` de la ligne annulée ;
- `event` : date de début (`starts_at`), jauge (`capacity`), statut ;
- `hello_asso_order` : commandes HelloAsso encaissées (`paid_at`, `amount_cents`) par type de formulaire
  (`evenements`, `adhesions`, `boutiques`, `HelloAssoConfig`).

## Critères d'acceptation

1. Endpoint `GET /api/v1/admin/stats/events?period=` (admin seulement, période comme en 42.1) qui renvoie, par
   tranche et en total avec la période précédente :
   - **inscriptions** (`registration.created_at`, annulées comprises : c'est l'élan d'inscription) ;
   - **annulations** (inscriptions au statut `cancelled`, datées de leur dernière mise à jour) ;
   - **recettes HelloAsso** encaissées (somme de `amount_cents` par `paid_at`), au total et par type de formulaire
     (événements, adhésions, boutique).
2. Un chiffre du moment : les événements publiés à venir (statuts publics, début dans le futur).
3. **Événements de la période** : ceux dont le début tombe dans la période, du plus récent au plus ancien, avec
   titre, date, statut, jauge, inscriptions actives (non annulées) et taux de remplissage. Titres d'événements
   seulement, jamais de nom de membre.
4. Section « Événements » de la page, entre Parties et Pelles : chiffres clés avec écart (recettes en euros), un
   graphe en barres des inscriptions et annulations, un graphe en barres des recettes par type de formulaire
   (barres côte à côte, un seul axe en euros), le tableau des événements de la période ; chaque graphe porte sa
   définition.
5. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-3) - Requête Events (interface Application, DBAL en Infrastructure), somme par tranche dans
  `DbalStatsReader`, endpoint ; tests fonctionnels.
- [x] **Task 2** (AC 4) - Front : section, graphes, tableau ; tests.
- [x] **Task 3** (AC 5) - Gates.

## Notes techniques

- La requête vit dans Events et lit `registration` et `hello_asso_order` en SQL, comme le compteur de l'accueil
  admin (`DbalDashboardStatsQuery`) le fait déjà.
- Une annulation datée par `updated_at` peut glisser si la ligne est modifiée ensuite ; une inscription annulée
  n'a plus de raison d'être modifiée, l'approximation est écrite dans la définition affichée.

## Dev Agent Record

- `EventStatsQueryInterface` / `DbalEventStatsQuery` (Events), `GET /api/v1/admin/stats/events`.
- `DbalStatsReader::trend()` accepte une somme (`sum:`) en plus du comptage et du distinct.
- Front : section Événements entre Parties et Pelles ; les recettes passent en euros arrondis dans le graphe, le
  chiffre clé les affiche au format monétaire.
