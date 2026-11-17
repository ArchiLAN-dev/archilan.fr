# Story 55.1 - Liens suivis et redirection

**Status:** review
**Epic:** 55 - Liens suivis (`_bmad-output/planning-artifacts/epics/epic-55-liens-suivis.md`)
**Date:** 2026-11-17
**Branche:** `feature/epic-55-story-1-liens-suivis`
**Dépend de:** rien.

## Story

En tant qu'admin,
je veux créer des liens courts `archilan.fr/l/{code}` qui comptent les passages puis redirigent,
afin de savoir quels liens diffusés amènent du monde chez nous, et combien de visiteurs on envoie ailleurs.

## Contexte

- Aucune mesure d'audience sur le site. Les liens partagés (Discord, réseaux, affiches) pointent directement vers les
  pages.
- Le contexte API `Content` porte déjà les partenaires (45.1) : les liens suivis y vont aussi (pas de nouveau contexte,
  55.3 les reliera aux partenaires).
- Upsert Postgres : précédent `INSERT ... ON CONFLICT` dans `DoctrineMemberStatsSnapshotRepository`.

## Décisions

1. **Un lien** : `code` (saisi par l'admin, `^[a-z0-9][a-z0-9-]{1,38}[a-z0-9]$`, unique), `label` (1 à 120), `target`,
   `active`. Cible interne = chemin qui commence par `/` (ni `//`, ni antislash) ; cible externe = `https://...` ; 500
   caractères au plus. Le sens (entrant, sortant) se déduit de la cible.
2. **Compteur** : table `tracked_link_visit` (lien, jour, domaine d'origine, passages), clé sur les trois ; incrément
   par `INSERT ... ON CONFLICT DO UPDATE`. Le jour est celui de Paris. Domaine d'origine : l'hôte du `Referer` en
   minuscules sans `www.`, vide si absent ou illisible. Aucune IP, aucun identifiant.
3. **Redirection** côté Next : `app/l/[code]/route.ts` appelle `POST /api/v1/links/{code}/visit` (public) avec
   `{ referrerHost, count }`, reçoit la cible et répond `302`. `count` est faux pour une requête `HEAD`, un prefetch
   (`Purpose`/`Sec-Purpose: prefetch`) ou un user-agent de robot d'aperçu ou d'indexation. Cible interne résolue sur
   `env.appUrl`. Code inconnu, lien désactivé, API injoignable : redirection vers l'accueil (le visiteur n'est jamais
   bloqué). `Cache-Control: no-store`.
4. **Pas d'open redirect** : la cible vient seulement de la base, jamais de la requête.
5. **Admin** : `/admin/liens` (section « Liens » du menu admin). Liste (code, libellé, cible, sens, actif, lien complet
   à copier), création, modification (libellé, cible, actif ; le code ne change pas, il est peut-être déjà imprimé),
   pas de suppression (on désactive). Les compteurs arrivent en 55.2.

## Critères d'acceptation

1. Un admin crée un lien `discord-nov` vers `/evenements/archilan-4` ; `archilan.fr/l/discord-nov` y redirige et
   compte un passage du jour.
2. Un lien vers `https://partenaire.example` redirige vers ce site et compte un passage.
3. Discordbot, une requête `HEAD` ou un prefetch sont redirigés sans être comptés.
4. Un code inconnu ou désactivé, ou une API injoignable, mène à l'accueil.
5. Code invalide ou déjà pris, cible invalide (`//x`, `http://`, `javascript:`) : refus avec un message.

## Tests et gates

6. Domaine : validation du code, de la cible, du sens. Fonctionnels : CRUD admin (admin seulement), visite comptée
   ou non, domaine d'origine, code inconnu en 404, upsert sur le même jour.
7. Front : route `/l/[code]` (robots, HEAD, repli), écran admin (création, erreurs, copie).
8. `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 5) - API : entité, migration, commande, requête admin, contrôleurs, endpoint de visite.
- [x] **Task 2** (AC 1-4) - Route Next `/l/[code]`.
- [x] **Task 3** (AC 1, 5) - Écran `/admin/liens`.
- [x] **Task 4** (AC 8) - Gates.

## Dev Agent Record

- **API** (contexte `Content`) : entités `TrackedLink` et `TrackedLinkVisit` (mappée pour le schéma, écrite seulement
  par l'upsert `DoctrineTrackedLinkRepository::countVisit`), migration `Version20261117130000`, commandes
  `ManageTrackedLinks` (create, edit ; 409 `tracked_link_code_taken`, 422 sur un champ invalide) et `RecordLinkVisit`
  (jour de Paris, domaine nettoyé : minuscules, sans `www.`, vide si ce n'est pas un nom d'hôte), requête
  `TrackedLinkCatalogQuery`. Routes : `GET|POST /api/v1/admin/links`, `PUT /api/v1/admin/links/{id}`,
  `POST /api/v1/links/{code}/visit` (public, 404 si inconnu ou désactivé).
- **Front** : `app/l/[code]/route.ts` (GET et HEAD) appelle `linkRedirect` (`features/links/link-redirect.ts`) :
  robots (motif `bot|crawler|spider|preview|...`, user-agent absent), HEAD et prefetch non comptés, 302 `no-store`
  avec `X-Robots-Tag: noindex`, cible revérifiée avant de rediriger, accueil si l'API ne répond pas en 3 s.
  Écran `/admin/liens`, onglet « Liens suivis » du Dashboard : liste, sens, état, copie du lien complet, création,
  modification (code figé).
- **Tests** : `TrackedLinkTest` (unitaire, domaine), `RecordLinkVisitTest` (jour de Paris avec `MockClock`, domaine
  d'origine), `TrackedLinkTest` (fonctionnel : CRUD, droits, visites comptées ou non, upsert), `link-redirect.test.ts`,
  `admin-links.test.tsx` ; le test du menu admin passe à 25 pages.
- **Vérification réelle** (2026-11-17, API sur 127.0.0.1:8000, build de prod Next sur 127.0.0.1:3107) : un navigateur
  venant de `t.co` est redirigé et compté sous `t.co` ; Discordbot et un HEAD sont redirigés sans être comptés ; un
  code inconnu mène à l'accueil.
