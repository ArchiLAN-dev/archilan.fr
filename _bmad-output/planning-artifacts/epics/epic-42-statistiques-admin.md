# Epic 42: Statistiques admin

**Statut :** proposé le 2026-10-03
**Date :** 2026-10-03
**Origine :** retour de Jean sur la page `/admin/pelles` (story 41.1) : les chiffres du site sont éparpillés
(compteurs de l'accueil admin, circulation des pelles), et aucun endroit ne montre l'activité dans le temps.

## Objectif

Une page **`/admin/statistiques`** qui répond à « comment va le site ? » d'un coup d'œil : l'activité de la
communauté, des parties, des événements et des pelles, sur une période choisie, en courbes plutôt qu'en compteurs.

## Principes

- **Une période pour toute la page** : 4 semaines, 12 semaines (par défaut) ou 12 mois. Les 4 et 12 semaines se
  découpent par semaine (lundi, UTC), les 12 mois par mois. Le choix vit dans l'URL (`?periode=12s`), pour qu'un
  lien partagé montre la même vue.
- **Une section par contexte, un endpoint par section** : chaque contexte expose sa propre requête de lecture
  (`/api/v1/admin/stats/{section}?period=`). Aucune requête ne traverse les contextes, et une section en erreur
  n'empêche pas les autres de s'afficher.
- **Chaque chiffre a une définition écrite** dans la page (une ligne sous le titre du graphe) : « actif » ou
  « joué » doivent dire ce qu'ils comptent.
- **Des tendances, pas un tableur** : un total sur la période, l'écart avec la période précédente, une courbe ou
  des barres par tranche. Pas d'export CSV dans cet epic.
- **Lecture seule, admin seulement.** Aucune donnée nominative : pas de classement de membres (même règle que le
  solde privé des pelles).
- **L'accueil `/admin` reste court** : alertes et éléments à traiter, quelques compteurs, et un lien vers la page
  de statistiques.

## Stories

| Story | Titre | Contenu |
|---|---|---|
| 42.1 | Page de statistiques, Communauté et Pelles | La page, le sélecteur de période, le socle commun (tranches, comparaison), la section Communauté, la section Pelles (reprise de `/admin/pelles`, qui redirige). |
| 42.2 | Section Parties | Runs privées créées et lancées, hebdos jouées, sessions actives par tranche, jeux les plus joués sur la période. |
| 42.3 | Section Événements | Inscriptions par tranche, remplissage des événements de la période, adhésions liées. |

## Dépendances

- 42.1 reprend la circulation des pelles : elle démarre après le merge de la 41.1 (PR #695).
- Les graphes réutilisent recharts, déjà utilisé par les récaps (epic 32).
