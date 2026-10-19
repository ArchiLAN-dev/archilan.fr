# Story 34.10: Invalider le cache d'une page publique quand son contenu change

**Status:** review
**Epic:** 34 - SEO & visibilité
**Date:** 2026-10-18

## Story

En tant qu'admin qui publie ou modifie un événement ou une actualité,
je veux que la page publique concernée affiche la nouvelle version tout de suite,
afin de ne pas attendre jusqu'à 5 minutes (ni voir une 404 en cache) après une publication, par exemple juste
avant de présenter un QR code vers la page.

## Contexte

La story 34.4 a mis les pages publiques en ISR, avec `export const revalidate = 300` et des fetchs
`next: { revalidate: 300 }` (`features/events/public-events-api.ts`). Le cache vit côté serveur Next.js : le
navigateur ne peut pas l'invalider, et aucune route du frontend n'appelle `revalidatePath` ou `revalidateTag`.

Conséquences observées :

- un événement publié reste invisible jusqu'à 5 minutes ; si sa page a été visitée avant publication, la 404 est
  servie depuis le cache pendant ce temps ;
- après la fenêtre, la première visite reçoit encore l'ancienne version (stale-while-revalidate) ;
- la seule façon de forcer est de redémarrer le conteneur frontend.

Pages ISR concernées (toutes à 300 s) : `/`, `/evenements`, `/evenements/[eventSlug]`, `/actualites`,
`/actualites/[postSlug]`, `/jeux`, `/jeux/[slug]`.

Principe retenu : **l'invalidation est déclenchée par l'écriture**, pas à la main. Quand l'API enregistre une
modification qui change une page publique, elle demande au frontend d'invalider les tags de cache de cette page.
La fenêtre de 300 s reste en place comme filet de sécurité.

## Acceptance Criteria

1. **Tags de cache côté frontend.** Les fetchs ISR portent des tags :
   - `getPublicEvents` → `events` ; `getPublicEvent(id)` → `events`, `event:{id}` ;
   - la liste et le détail des actualités → `posts`, `post:{slug}` ;
   - l'accueil réutilise ces fetchs, il hérite donc de leurs tags (aucun tag propre à ajouter s'il n'a pas de
     fetch dédié ; sinon `home`).
   `revalidate: 300` est conservé.
2. **Route d'invalidation.** `POST /api/revalidate` (route handler `app/api/revalidate/route.ts`) :
   - corps JSON `{ "tags": string[] }`, 1 à 20 tags, chacun `^[a-z]+(:[A-Za-z0-9_-]{1,120})?$` ;
   - en-tête `Authorization: Bearer <secret>`, comparé en temps constant à `REVALIDATE_SECRET` ;
   - secret absent ou vide côté serveur → 503 et rien n'est invalidé ; secret faux → 401 ; corps invalide → 400 ;
   - succès → `revalidateTag(tag)` pour chaque tag, réponse 200 `{ "revalidated": [...] }` ;
   - aucune autre méthode (405).
3. **Secret server-only.** `REVALIDATE_SECRET` est lu via `src/lib/env.ts` dans une entrée serveur qui n'est
   jamais exposée au client (pas de préfixe `NEXT_PUBLIC_`, pas d'import depuis un composant client). Ajouté à
   `.env.example` vide, avec un commentaire.
4. **Déclenchement par l'API, après commit.** Une modification qui change une page publique dispatche, après le
   flush, un job async `RevalidatePublicPagesJob(tags)` :
   - événement : `AdminEventDrafts::create`, `update`, `transition` (publication, dépublication, clôture...),
     `configurePrivateAccess`, `UploadEventCoverImageCommand`, `ManageEventGalleryCommand::upload` et `delete`,
     `AdminEventRecap::attach` → tags `events`, `event:{id}` ;
   - actualité : `AdminPostCatalog::create`, `update`, `publish`, `unpublish` → `posts`, `post:{slug}` (et
     l'ancien slug si le slug change) ;
   - une écriture qui échoue en validation ne dispatche rien.
5. **Port et adaptateur.** Côté Application, un port `PublicPageCacheInterface::invalidate(list<string> $tags)`
   (contexte `Shared`). L'adaptateur HTTP (Infrastructure) appelle `POST {FRONTEND_INTERNAL_URL}/api/revalidate`
   avec le secret, timeout 5 s. Le handler du job :
   - réussit sur 200 ;
   - lève sur 5xx ou erreur réseau, pour que Messenger réessaie (politique de retry existante du transport async) ;
   - logge et abandonne sur 401 ou 400 (configuration, pas transitoire) : `frontend.revalidate.rejected`.
   Une double invalidation est sans effet, le job n'a pas besoin de clé d'idempotence.
6. **Configuration.** Nouvelles variables API : `FRONTEND_INTERNAL_URL` (en prod `http://archilan-frontend:3000`,
   le réseau Docker, pas le domaine public) et `REVALIDATE_SECRET` (même valeur que côté frontend). Si l'une des
   deux est vide, l'adaptateur ne fait rien et logge une fois en `info` (`frontend.revalidate.disabled`) : le dev
   local et la CI tournent sans.
7. **Commande manuelle.** `php bin/console app:frontend:revalidate <tag>...` invalide à la main (même port), pour
   un correctif en base ou un doute en prod.
8. **Effet observable.** Après publication d'un événement, sa page publique et la liste `/evenements` montrent la
   nouvelle version dès la visite suivante, y compris si une 404 était en cache.

## Tasks / Subtasks

- [x] Frontend
  - [x] Tags sur les fetchs ISR des événements et des actualités (AC 1)
  - [x] `app/api/revalidate/route.ts` + tests (secret absent, faux, corps invalide, succès, méthode) (AC 2)
  - [x] Entrée server-only `revalidateSecret` dans `env.ts`, `.env.example` (AC 3)
- [x] API
  - [x] Port `Shared/Application/Port/PublicPageCacheInterface`, adaptateur HTTP (AC 5, 6)
  - [x] `RevalidatePublicPagesJob` + handler, routé `async` dans `messenger.yaml` (AC 5)
  - [x] Dispatch après flush dans les écritures listées (AC 4)
  - [x] Commande `app:frontend:revalidate` (AC 7)
  - [x] Tests : dispatch avec les bons tags, rien sur une écriture refusée ; adaptateur (200, 400/401, 5xx, réseau,
        config vide) ; handler ; commande
- [ ] Déploiement : `envs/frontend.env` et `envs/api.env` reçoivent le même `REVALIDATE_SECRET` ;
      `envs/api.env` reçoit `FRONTEND_INTERNAL_URL` (documenté dans les `*.env.example`, à poser en prod)
- [x] Gates verts des deux côtés

## Dev Notes

- `revalidateTag` invalide le Data Cache de tous les fetchs qui portent le tag, et donc les pages ISR qui les
  utilisent, y compris une page en 404 rendue par `notFound()`. Vérifier dans `node_modules/next/dist/docs/` la
  signature de `revalidateTag` pour la version installée (AGENTS.md : « This is NOT the Next.js you know »).
- Un seul conteneur frontend en prod (`archilan-frontend`), cache ISR local au conteneur : un appel suffit. Si un
  jour il y a plusieurs réplicas, il faudra un cache partagé ou un appel par réplica.
- Hors périmètre : le catalogue de jeux (`/jeux`, mis à jour par la synchro, très fréquent : le cache de 5 min
  reste adapté), les pages non ISR.
- Effets de bord après commit uniquement (CLAUDE.md, AC-A4) : le dispatch suit le flush, jamais dans la
  transaction.

## Dev Agent Record

### Implementation Notes

- **`revalidateTag(tag, { expire: 0 })`, pas `"max"`.** Next.js 16.3 a rendu le second argument obligatoire et
  recommande `"max"`, qui sert encore la version périmée au visiteur suivant pendant la reconstruction : une 404
  en cache le resterait une visite de plus. `{ expire: 0 }` fait attendre la requête suivante sur des données
  fraîches, ce que demande l'AC 8.
- La route renvoie 405 d'elle-même : seul `POST` est exporté. Le secret est comparé en temps constant sur des
  empreintes SHA-256 (longueurs égales pour `timingSafeEqual`). Les règles sont dans `src/lib/revalidate-request.ts`,
  testées sans serveur Next.
- `serverEnv.revalidateSecret()` est lu à l'appel, depuis l'environnement du serveur en marche : pas de préfixe
  `NEXT_PUBLIC_`, donc jamais inliné dans le bundle client.
- L'accueil hérite des tags `events` et `posts` de ses fetchs ; ses recaps (`/events/{id}/parties`) portent en plus
  `events` et `event:{id}`.
- **Écart avec l'AC 4 :** le slug d'une actualité ne change jamais à la modification (`AdminPostCatalog::update`
  ne le touche pas), il n'y a donc pas d'« ancien slug » à invalider ; `postChanged(...$slugs)` l'accepterait.
  `UploadPostCoverImageCommand` est ajouté à la liste : une couverture change la page. Laissés hors périmètre :
  `ReserveRegistration` (compteur de places, déjà en temps réel) et `AdminEventGameSelection` (non affiché sur les
  pages publiques).
- Adaptateur : 200 = fait ; 400/401 = refus de configuration, loggé `frontend.revalidate.rejected`, pas de retry ;
  5xx (dont 503 secret absent côté frontend) et erreur réseau = exception, retry du transport `async` puis
  `async_failed`. URL ou secret vide = rien, log `frontend.revalidate.disabled`.
- Prod : le worker (`archilan-api-worker`) et `archilan-frontend` partagent le réseau `default`, d'où
  `FRONTEND_INTERNAL_URL=http://archilan-frontend:3000`.

### File List

- `frontend/src/app/api/revalidate/route.ts`, `route.test.ts` (nouveaux)
- `frontend/src/lib/revalidate-request.ts` (nouveau), `frontend/src/lib/env.ts`, `frontend/.env.example`
- `frontend/src/features/events/public-events-api.ts`, `frontend/src/features/content/public-posts-api.ts`,
  `frontend/src/features/home/home-api.ts`, `frontend/src/features/events/public-cache-tags.test.ts` (nouveau)
- `api/src/Shared/Application/Port/PublicPageCacheInterface.php`, `PublicPageCacheOutcome.php` (nouveaux)
- `api/src/Shared/Application/Message/RevalidatePublicPagesJob.php`,
  `api/src/Shared/Application/Handler/RevalidatePublicPagesJobHandler.php`,
  `api/src/Shared/Application/Exception/PublicPageCacheUnavailableException.php`,
  `api/src/Shared/Application/Support/PublicPagesRevalidation.php`,
  `api/src/Shared/Infrastructure/Http/HttpPublicPageCache.php`,
  `api/src/Shared/Presentation/Command/RevalidateFrontendCommand.php` (nouveaux)
- `api/src/Events/Application/Service/AdminEventDrafts.php`, `api/src/Events/Application/Command/AdminEventRecap.php`,
  `ManageEventGalleryCommand.php`, `UploadEventCoverImageCommand.php`,
  `api/src/Content/Application/Service/AdminPostCatalog.php`,
  `api/src/Content/Application/Command/UploadPostCoverImageCommand.php`
- `api/config/services.yaml`, `api/config/packages/messenger.yaml`, `api/.env`
- `envs/api.env.example`, `envs/frontend.env.example`
- `api/tests/Unit/Shared/PublicPageCacheTest.php`, `api/tests/Functional/PublicPagesRevalidationTest.php` (nouveaux)
