# Story 50.5 - Durcissements restants

**Epic :** 50 - Sécurité
**Statut :** review
**Date :** 2026-11-04
**Branche :** `feature/epic-50-story-5-durcissements-restants`

## Story

En tant que responsable de la plateforme, je veux terminer les points laissés ouverts par l'audit du 2026-11-03,
afin que les échanges internes et les données des joueurs suivent les mêmes règles partout.

## Critères d'acceptation

1. **Webhooks de l'orchestrateur.** Après la signature, l'API refuse (401) un webhook dont `timestamp` est absent,
   invalide ou à plus de 5 minutes de son horloge. Le format d'envoi (RFC 3339, nanosecondes, UTC) reste accepté.
2. **Résultats d'un event privé.** Un event `isPublic=false` reste listé, mais ses résultats suivent le public de ses
   récaps (story 4.23) : participants confirmés et admins. Les autres reçoivent 404.
3. **Audit des dépendances du front.** `security.yml` lance `pnpm audit`, bloquant à partir de `high`. postcss est
   corrigé par override.
4. **Avertissements semgrep.** Les 42 sont tous passés en revue et classés ci-dessous.

## Tâches

- [x] `OrchestratorWebhookFreshness`, appelé par `OrchestratorWebhookController` (AC 1)
- [x] `OrchestratorWebhookFreshnessTest`, `OrchestratorWebhookTest` (AC 1)
- [x] `SessionResultsQuery` passe par `SessionRecapAudience::canViewEvent`, test dans `PlayerDataAccessControlTest` (AC 2)
- [x] Job `pnpm-audit`, override `postcss@<8.5.23` (AC 3)
- [x] Revue semgrep (AC 4)

## Notes

### Webhooks

- Seul `orchestrateur/internal/webhook/sender.go` écrit vers ce contrôleur. Tous ses envois passent par `Send`, qui pose
  `timestamp`.
- Le bridge ne passe pas par ce contrôleur : il appelle ses propres routes internes avec un secret partagé, et n'a donc
  pas ce champ. Ces routes ne changent pas.
- Un refus laisse la trace `orchestrateur.webhook.stale`. L'orchestrateur ne réessaie pas : un écart d'horloge de plus
  de 5 minutes entre les deux machines ferait perdre les événements, les hôtes doivent rester synchronisés.
- Rejouer un webhook à la main en local demande désormais un `timestamp` frais, signé à nouveau.

### Sens de `isPublic`

`isPublic=false` ne masque pas l'event : il reste au catalogue et sur sa page (4.21 exclut de le retirer du catalogue,
le front l'affiche comme réservé). Il restreint l'inscription (`PrivateEventAccess`), et l'activité de jeu : 4.23 AC 5
réserve récaps, fil et liste des parties aux participants confirmés et aux admins. Les résultats sont cette même
activité, et `SessionRecapAudience` documente qu'elle ne doit pas diverger entre surfaces.

La page `/evenements/{id}/resultats` est rendue côté serveur, donc sans compte : pour un event privé, elle affiche
« Aucun résultat disponible », y compris à un participant. Aucun lien du site n'y mène, et le récap reste accessible
depuis la page de l'event. L'API répond bien aux participants et aux admins.

### Audit du front

Reste après l'override, sans `high` ni `critical` : minimatch et glob (moderate, via eslint et jest) et deepmerge (low,
sans correctif, via jest). Ce sont des outils de dev, absents de l'image de production ; forcer leur version majeure
n'est pas justifié, ils suivront les mises à jour d'eslint et de jest.

`frontend.yml` lançait déjà le même audit, uniquement quand `frontend/` change. Le job de `security.yml` le complète sur
le passage hebdomadaire et garde `--ignore-registry-errors` pour la raison donnée dans `frontend.yml`.

### Revue semgrep

Version 1.120.0, mêmes règles que le workflow : 42 WARNING, 0 ERROR. Aucun `nosemgrep` ajouté.

**`doctrine-orm-dangerous-query` : 40, tous faux positifs.** La règle signale toute concaténation dans un appel au
QueryBuilder. Aucun fragment ne vient d'une entrée :

| Nombre | Fichiers | Provenance du fragment |
|---|---|---|
| 26 | `DbalCommunityPresenceQuery` (6), `DbalFriendActivitySourceQuery` (2), `DbalRecapSuperlativesQuery` (3), `DbalPlayerStatsQuery` (8), `DbalMyRunSlotsQuery` (2), `DbalRunPlayerActivityQuery` (3), `DbalRecapExchangesQuery` (2) | Constantes de `DbalSlotPlayerSource` et de la classe, expressions littérales locales ; les valeurs passent en paramètres liés |
| 8 | `DbalLeaderboardQuery` | Noms de table lus dans les métadonnées Doctrine, constante `RUN_START`, et `$axis` qui ne fait que choisir entre deux littéraux |
| 3 | `DbalAdminMembershipListQuery` | Constante `STATUS_EXPR` |
| 1 | `DbalFriendSessionContextQuery` | Littéral coupé sur deux lignes, `:viewer` est lié |
| 1 | `DbalApworldIncidentListQuery` | `sprintf` d'un littéral et d'une constante entière |
| 1 | `DbalParticipantTwitchLinksQuery` | Méthode privée, appelée avec des littéraux seulement |

**`react-dangerouslysetinnerhtml` : 2.**

- `components/json-ld.tsx` : faux positif. `serializeJsonLd` échappe `<`, `>` et `&`.
- `app/(public)/actualites/[postSlug]/page.tsx` : le corps d'un article est rendu tel qu'il est stocké, sans filtrage,
  ni à l'écriture ni à la lecture. Seuls les admins écrivent ces articles. Risque accepté pour l'instant, non corrigé
  ici : une liste blanche demande une nouvelle dépendance, doit couvrir ce que produit l'éditeur (images, liens, vidéos
  intégrées) et les articles déjà publiés. À traiter à part, avec le passage de la CSP en mode bloquant.

**Couverture.** Semgrep 1.120.0, la version épinglée, n'analyse que partiellement 200 fichiers (197 PHP, 3 TSX) : sa
grammaire bute surtout sur `readonly` et sur `satisfies`. Le même scan en 1.182.1 n'en laisse qu'un (TSX) et donne
exactement les mêmes 42 résultats. Une recherche complémentaire sur `api/src` (SQL interpolé ou concaténé, QueryBuilder
nourri d'une variable) n'a trouvé que des constantes, des littéraux et `StatsPeriod::$granularity`, restreint à `week`
ou `month`. Monter la version épinglée dans `security.yml` donnerait au gate la même couverture.
