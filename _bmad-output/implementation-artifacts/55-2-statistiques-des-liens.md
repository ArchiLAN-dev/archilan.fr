# Story 55.2 - Statistiques des liens

**Status:** review
**Epic:** 55 - Liens suivis (`_bmad-output/planning-artifacts/epics/epic-55-liens-suivis.md`)
**Date:** 2026-11-17
**Dépend de:** 55.1.

## Story

En tant qu'admin,
je veux voir combien de passages chaque lien suivi a reçus et d'où ils venaient,
afin de savoir quels canaux amènent du monde et ce qu'on envoie à nos partenaires.

## Décisions

1. Sur `/admin/liens` : par lien, total, 7 et 30 derniers jours (jour de Paris), filtre par sens (entrants,
   sortants, tous), tri par passages sur 30 jours.
2. Détail d'un lien : courbe par jour sur 90 jours (jours sans passage à zéro), domaines d'origine principaux
   (« direct » pour le vide), sur 90 jours.
3. Mention visible : « Des passages, pas des visiteurs uniques : sans cookie ni IP, deux clics de la même personne
   comptent deux fois. Les aperçus Discord et les robots ne comptent pas. »

## Critères d'acceptation

1. Les chiffres 7 j, 30 j et total d'un lien sont justes, jour de Paris compris.
2. La courbe montre 90 jours, trous compris ; les origines sont triées par passages.

## Tests et gates

3. Fonctionnels sur la requête de stats (bornes de jours, origines). Front : tableau, filtre, courbe.
4. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** - Requête DBAL des stats et endpoint admin.
- [x] **Task 2** - Colonnes et filtre sur `/admin/liens`, détail d'un lien (courbe recharts).
- [x] **Task 3** - Gates.

## Dev Agent Record

### Implémentation

- API : `TrackedLinkStatsQueryInterface` (Application/Query) et `DbalTrackedLinkStatsQuery` (Infrastructure/Dbal),
  SUM et CASE WHEN dans le QueryBuilder. `TrackedLinkCatalogQuery::forAdmin()` ajoute `visits {total, last7, last30}`,
  `detail(id)` renvoie 90 jours (jours vides à 0) et les 10 premières origines. Le jour de référence est celui de Paris
  (`ClockInterface`). Endpoint `GET /api/v1/admin/links/{id}/stats` (404 si l'id est inconnu).
- Front : `links-api.ts` (`visits`, `fetchLinkStats`, gardes de type), `admin-links.tsx` (filtre Tous / Entrants /
  Sortants, tri sur 30 jours puis total puis récence, compteurs par ligne, bouton Statistiques), nouveau
  `admin-link-stats.tsx` (trois tuiles, barres recharts sur 90 jours avec tableau `sr-only`, origines avec part en %,
  « Direct ou inconnu » pour une origine vide, mention des passages).

### Vérifications

- Test fonctionnel `testStatsCountTheLastDaysAndTheOrigins` : bornes 0/6/7/29/30/90 jours, 90 jours, origines triées.
- Jest : tri et filtre, compteurs, origines et parts, libellés de jour.
- `./scripts/gates.sh` vert (api ParaTest + front).
- Vérification réelle sur `/admin/liens` avec des données semées (61 jours de passages) : 44 / 182 / 364, courbe et
  origines cohérentes avec la base ; données de démo retirées ensuite.

### Fichiers

- `api/src/Content/Application/Query/TrackedLinkStatsQueryInterface.php` (nouveau)
- `api/src/Content/Infrastructure/Dbal/DbalTrackedLinkStatsQuery.php` (nouveau)
- `api/src/Content/Application/Query/TrackedLinkCatalogQuery.php`
- `api/src/Content/Presentation/Controller/TrackedLinkController.php`
- `api/config/services.yaml`
- `api/tests/Functional/TrackedLinkTest.php`
- `frontend/src/features/links/links-api.ts`
- `frontend/src/features/admin/admin-links.tsx`
- `frontend/src/features/admin/admin-link-stats.tsx` (nouveau)
- `frontend/src/features/admin/admin-links.test.tsx`
