# Story 42.1: Page de statistiques admin, Communauté et Pelles

**Status:** review
**Epic:** 42 - Statistiques admin
**Date:** 2026-10-03

## Story

En tant qu'admin,
je veux une page qui montre l'activité du site sur une période choisie, en graphes,
afin de savoir comment va la communauté sans assembler des compteurs dispersés.

## Contexte

Aujourd'hui l'accueil `/admin` affiche des compteurs instantanés (endpoint `dashboard-stats` : événements publiés,
inscriptions, jeux, comptes, adhérents, recettes), et la story 41.1 a ajouté `/admin/pelles` pour la circulation
des pelles. Rien ne montre une évolution dans le temps. Cette story pose la page et son socle, avec les deux
premières sections ; les parties (42.2) et les événements (42.3) suivront sur le même socle.

## Critères d'acceptation

### Page et période

1. Nouvelle page **`/admin/statistiques`**, entrée « Statistiques » en tête de la navigation admin, sous Dashboard
   (décision de Jean, 2026-10-03), réservée aux admins (403 sinon, comme les autres endpoints admin).
2. **Sélecteur de période** en haut de page : 4 semaines, 12 semaines (par défaut), 12 mois. Le choix est dans
   l'URL (`?periode=4s|12s|12m`) ; une valeur inconnue retombe sur 12 semaines.
3. Découpage : par semaine (lundi 00:00 UTC) pour 4 et 12 semaines, par mois (1er du mois, UTC) pour 12 mois. La
   tranche en cours est incluse et marquée « en cours » dans l'infobulle. Les tranches sans donnée valent 0 (axe
   de temps régulier).
4. **Comparaison** : chaque chiffre clé affiche son total sur la période et l'écart avec la période précédente de
   même durée (en valeur et en %, « nouveau » si la période précédente vaut 0).
5. Chaque section se charge indépendamment : une section en erreur affiche son message sans casser les autres.

### Section Communauté (contexte Community, avec Identity et Membership en lecture)

6. Endpoint `GET /api/v1/admin/stats/community?period=` qui renvoie, par tranche et en total :
   - **comptes créés**, comptés à leur création (une suppression ultérieure ne réécrit pas le passé) ;
   - **adhésions démarrées** (date de début d'adhésion) ;
   - **membres actifs** : comptes distincts dont un slot (à eux ou en co-joueur) a fait au moins un check dans la
     tranche (un item envoyé ou un goal dans le fil de session, epic 32) ; définition validée par Jean le 2026-10-03 ;
   - **amitiés acceptées** et **succès débloqués**.
   Plus deux chiffres instantanés : comptes existants (non supprimés), adhérents à jour.
7. Rendu : chiffres clés en tête (comptes créés, membres actifs, adhésions), puis un graphe en barres des comptes
   créés et un graphe en ligne des membres actifs ; chaque graphe porte sa définition en une ligne.

### Section Pelles (contexte Wallet)

8. La circulation de la 41.1 devient une section de la page, sur la période choisie (elle est aujourd'hui figée à
   12 semaines) : en circulation (instantané), créées et détruites (total, écart, graphe par tranche), table par
   motif sur la période. L'endpoint `GET /api/v1/admin/pelles/circulation` est remplacé par
   `GET /api/v1/admin/stats/pelles?period=` (décision de Jean, 2026-10-03 : les sections suivent le même schéma).
9. **`/admin/pelles` redirige** vers `/admin/statistiques#pelles` ; l'entrée « Pelles » quitte la navigation admin.

### Accueil admin

10. L'accueil `/admin` garde ses compteurs et gagne un lien « Voir les statistiques ».

### Qualité

11. Graphes : recharts (comme les récaps), un seul axe vertical par graphe, infobulle au survol, légende dès deux
    séries, et un tableau caché pour les lecteurs d'écran avec les mêmes chiffres. Lisible sur mobile (graphes en
    pleine largeur, pas de défilement horizontal de la page).
12. Aucune donnée nominative dans les réponses (que des comptes).
13. Gates : `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 2-4) - Socle : objet période (`StatsPeriod` : bornes, tranches, période précédente), testé
  unitairement (bascule d'année, semaine à cheval sur deux mois, tranche en cours).
- [x] **Task 2** (AC 6, 12) - Requête Communauté (interface Application, DBAL en Infrastructure), endpoint, tests
  fonctionnels (tranches vides à 0, admin seulement, période inconnue).
- [x] **Task 3** (AC 8) - Circulation des pelles paramétrée par période ; tests.
- [x] **Task 4** (AC 1, 5, 7, 9-11) - Front : page, sélecteur, chiffres clés avec écart, graphes, sections
  indépendantes, redirection `/admin/pelles`, lien depuis l'accueil ; tests.
- [x] **Task 5** (AC 13) - Gates.

## Notes techniques

- Le socle période vit dans `Shared` (Application) : c'est un calcul de dates pur, sans dépendance à un contexte.
  Chaque contexte reçoit les bornes et rend des tranches ; le regroupement SQL se fait avec
  `date_trunc('week'|'month', ... AT TIME ZONE 'UTC')`, et le remplissage des tranches vides côté PHP.
- « Membres actifs » s'appuie sur le fil persisté des sessions (`session_feed_event`, epic 32) : `sender_slot`
  et `session_id` mènent au slot, donc à son joueur. Ni l'instantané de joueurs (une ligne par session, sans
  historique) ni `session_slot.last_check_at` (seulement le dernier check) ne gardent l'activité semaine par
  semaine. Limites à écrire dans la définition affichée : le fil n'existe que depuis l'epic 32 (juillet 2026), et
  un co-joueur sans slot à son nom n'est pas compté. Lecture DBAL côté Community ou Sessions : à placer dans
  Sessions si la requête ne lit que des tables de Sessions, l'endpoint Communauté assemblant alors deux requêtes.
- Les comptes supprimés restent comptés dans « comptes créés » de leur tranche (ligne anonymisée) ; ils sortent
  du chiffre instantané « comptes existants ».
- Pas de cache dans cette story : les volumes actuels tiennent en quelques millisecondes. À mesurer avant d'en
  ajouter un.
- Démarre après le merge de la 41.1 (PR #695), puisqu'elle déplace sa section Pelles.

## Dev Agent Record

- **Socle** : `Shared\Application\Support\StatsPeriod` (codes `4s`/`12s`/`12m`, repli sur `12s`), tranches UTC,
  période précédente, `series()` qui remplit les tranches vides ; 6 tests unitaires (bascule d'année, lundi minuit,
  fuseau local, 12 mois).
- **Communauté** : `CommunityStatsQueryInterface` / `DbalCommunityStatsQuery`, `GET /api/v1/admin/stats/community`.
  Membres actifs : `session_feed_event` (types item-received et goal) relié au slot par `session_id` +
  `slot_name = sender_name` (le même lien que `RecordSessionFeedEvent`), puis aux joueurs par
  `DbalSlotPlayerSource` : les co-joueurs comptent. Le total de la période est un distinct, pas une somme. La
  requête vit dans Community et lit les tables des autres contextes en SQL, comme le compteur de l'accueil admin.
- **Pelles** : `PelleCirculationQueryInterface::circulation(StatsPeriod)`, route `/api/v1/admin/stats/pelles`
  (l'ancienne route est retirée, seule `/admin/pelles` l'utilisait). En circulation reste un instantané.
- **Front** : `features/stats` (API, `TrendChart` recharts avec tableau caché, `KeyFigure` avec écart),
  `/admin/statistiques` (période dans `?periode=`, sections chargées séparément), `/admin/pelles` redirige,
  entrée « Statistiques » sous Dashboard, lien depuis l'accueil admin.
