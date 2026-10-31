# Story 45.1 - Gérer les partenaires en admin (socle)

**Status:** review
**Epic:** 45 - Mettre en avant nos partenaires (`_bmad-output/planning-artifacts/epics/epic-45-partenaires.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-45-story-1-partenaires-admin`
**Dépend de:** rien. Prérequis de 45.2, 45.3 et 45.4.

## Epic 45 en bref

L'association noue des partenariats (5 au maximum, sans niveaux, aucune contrepartie promise) et veut les montrer
sur le site et pendant les événements.

- **45.1 (cette story)** : le socle, gestion des partenaires en admin et lecture publique.
- 45.2 : section sur l'accueil, page `/partenaires`, lien texte dans le footer.
- 45.3 : partenaires rattachés à un événement (page de l'event, récap).
- 45.4 : écran partenaires pour OBS et pour la télé de la LAN.

## Story

En tant qu'admin,
je veux ajouter, modifier, ordonner, masquer et supprimer les partenaires de l'association, avec leur logo,
afin que le site affiche toujours la bonne liste sans passer par un développeur.

## Contexte (cartographie du 2026-10-30)

Rien n'existe : aucune notion de partenaire dans l'API ni dans le front (les occurrences de « partner » dans le code
concernent les quêtes et succès sociaux, sans rapport).

**Modèle à copier : les annonces d'accueil (story 10.14)**, même contexte `Content`, même forme (entité admin +
image dans le bucket public) :

- Entité : `api/src/Content/Domain/Entity/HomeAnnouncement.php` (constantes `MAX_*`, `create()` qui délègue à
  `edit()`, `\DomainException` portant un code, `isAcceptedLink()`, `attachImage()` / `removeImage()`).
- Commande : `api/src/Content/Application/Command/ManageHomeAnnouncements.php` (+ `HomeAnnouncementDraft.php`) :
  table `MESSAGES` code → message français, `invalid()` qui convertit en `ValidationException`, `get()` qui lève
  `NotFoundException`, upload via `MinioStorageInterface` + `PublicMediaUrlResolver::bucket()`, clé neuve à chaque
  upload, `ServiceUnavailableException('...', 'storage_unavailable')` si le stockage échoue (avec log PSR-3).
- Requête : `api/src/Content/Application/Query/HomeAnnouncementQuery.php` (`forVisitors()` / `forAdmin()`, URL de
  l'image par `$this->publicMedia->resolve($key)`).
- Dépôt : `api/src/Content/Domain/Repository/HomeAnnouncementRepositoryInterface.php` +
  `api/src/Content/Infrastructure/Doctrine/DoctrineHomeAnnouncementRepository.php`, câblé dans
  `api/config/services.yaml` (ligne ~501, à côté de `PostRepositoryInterface`).
- Contrôleur : `api/src/Content/Presentation/Controller/HomeAnnouncementController.php` (`RequiresAuthTrait`,
  `requireAuthenticatedAdmin()`, `ALLOWED_MIMES`, contrôle de taille, `ApiAccessGuard::errorResponse()`, les
  exceptions typées sont converties en HTTP par le listener de l'epic 35 : pas de try/catch dans le contrôleur).
- Migration : `api/migrations/Version20261012100000.php`.
- Tests : `api/tests/Functional/HomeAnnouncementTest.php` (`FunctionalTestCase`, `NullMinioStorage::reset()`,
  `loginAs()`, `createUser(..., ['ROLE_USER', 'ROLE_ADMIN'])`, `errorCode()`), `api/tests/Unit/Content/HomeAnnouncementTest.php`.
- Front : `frontend/src/features/content/home-announcement-api.ts` (types, gardes `is*`, `dataOf`, `send`,
  `errorMessage`, `SaveResult`), `frontend/src/features/admin/admin-home-announcements.tsx` (TanStack Query, clé
  `ADMIN_*_KEY`, `Dialog`, `ConfirmDialog`, `buttonVariants`), page
  `frontend/src/app/(admin)/admin/annonces/page.tsx`, test `frontend/src/features/content/home-announcement.test.tsx`.
- Menu admin : `frontend/src/components/admin-shell.tsx`, groupe « Contenu » (Événements, Actualités, Annonces, Jeux).

**Réordonnancement existant** : collections de succès, `POST .../reorder` avec `{ids: [...]}`
(`AdminAchievementCollectionController::reorder`, `AdminAchievementCollectionService::reorder` : position = rang
dans la liste, ids inconnus ignorés). Le front y utilise `@dnd-kit` ; ici, avec 5 éléments, des boutons
« monter / descendre » suffisent.

## Décisions

1. **Contexte `Content`**, entité `Partner`, table `partner`. Pas de nouveau contexte.
2. **Pas de niveaux, pas de plafond technique.** La mise en page publique (45.2) est pensée pour 1 à 5 partenaires ;
   au-delà de 5 partenaires visibles, l'admin affiche un avertissement, sans blocage.
3. **Visible = affiché ET avec un logo.** Un partenaire sans logo peut exister (on le prépare avant d'avoir le
   fichier) mais n'apparaît jamais côté public ; l'admin le signale (« Masqué : pas de logo »).
4. **Logo : PNG, JPEG ou WebP, 2 Mo au plus. Pas de SVG** (un SVG peut porter du script : risque XSS sur le bucket
   public). Un logo reçu en SVG est converti en PNG avant l'envoi ; l'admin le dit sous le champ.
5. **Lien du partenaire : `https://` uniquement** (site externe). Pas de lien relatif : un partenaire n'est pas une
   page du site.
6. **Noms neutres face aux bloqueurs de pub** (cf. epic, principe « Résister aux bloqueurs de pub ») : clé d'objet
   `content-logos/{id}/logo-{hex}.{ext}`, aucun `sponsor`, `ad`, `banner`, `promo` dans les clés, ids, classes ni
   routes front. Les routes API (`/api/v1/partners`, `/api/v1/admin/partners`) ne sont lues côté public que par le
   serveur Next (ISR, 45.2) : un bloqueur ne les voit pas.
7. **Une seule commande `ManagePartners`** (même forme que `ManageHomeAnnouncements`) : `create`, `edit`, `delete`,
   `reorder`, `attachLogo`, `removeLogo`. L'état « affiché » fait partie du brouillon (case à cocher du
   formulaire), pas d'endpoint dédié.
8. **Suppression** : supprime la ligne ; l'objet du logo reste dans le bucket (comme les annonces). Confirmation
   obligatoire côté admin.

## Critères d'acceptation

### Domaine

1. `Partner` (`final`, `Content/Domain/Entity/`) : `id` (32 hex), `name` (1 à 80 caractères, trimé), `description`
   (0 à 160 caractères, trimée), `url` (https, 500 max), `active` (bool), `position` (int >= 0), `logoKey`
   (nullable), `createdAt`, `updatedAt`. Pas de setter : `create()`, `edit()`, `moveTo(int $position, $now)`,
   `attachLogo($key, $now)`, `removeLogo($now)`, `isVisible(): bool` (= actif et logo présent).
2. Codes d'erreur levés par `\DomainException` : `partner_name_invalid`, `partner_description_invalid`,
   `partner_url_invalid`, `partner_position_invalid`. `isAcceptedUrl()` refuse vide, espaces, plus de 500
   caractères, `http://`, `javascript:`, `//host`, chemins relatifs ; accepte une URL `https://` valide
   (`FILTER_VALIDATE_URL`).

### API

3. Migration `partner` (id, name, description, url, active, position, logo_key, created_at, updated_at, avec un
   index sur `position`), réversible. Nom : un timestamp une seconde après la dernière migration au moment du
   commit (aujourd'hui `Version20261025200003` → `Version20261025200004`).
4. `GET /api/v1/partners` (public, sans auth) : `{data: [{id, name, description, url, logoUrl}], meta: {}}`, les
   seuls partenaires visibles (AC 1), triés par `position` puis `createdAt`. Liste vide sans partenaire visible.
5. `GET /api/v1/admin/partners` (admin) : tous les partenaires, mêmes champs + `active` et `visible`, triés de la
   même façon.
6. `POST /api/v1/admin/partners` (admin) : JSON `{name, description, url, active}` → 201 `{data: {id}}`. Le nouveau
   partenaire prend la dernière position. Champs invalides → 422 avec le code de l'AC 2 et un message français.
7. `PUT /api/v1/admin/partners/{id}` → 204 ; `DELETE /api/v1/admin/partners/{id}` → 204 ; id inconnu → 404
   `partner_not_found`.
8. `POST /api/v1/admin/partners/reorder` `{ids: [...]}` → 204 : position = rang dans la liste ; ids inconnus
   ignorés ; un partenaire absent de la liste garde sa position.
9. `POST /api/v1/admin/partners/{id}/logo` (multipart `file`) → 204 ; refus 422 `missing_file`, `image_too_large`
   (> 2 Mo), `upload_error`, `image_invalid_type` (tout sauf PNG/JPEG/WebP, **SVG compris**, détecté par le type
   MIME réel et pas l'extension) ; stockage en panne → 503 `storage_unavailable`. `DELETE .../logo` → 204.
10. Toutes les routes admin : 401 sans session, 403 pour un membre non admin.

### Admin (front)

11. Page `/admin/partenaires` (`metadata.title` « Partenaires »), entrée « Partenaires » (icône lucide `Handshake`)
    dans le groupe « Contenu » de `admin-shell.tsx`, après « Annonces ».
12. Liste dans l'ordre d'affichage : logo (ou emplacement vide), nom, lien (domaine seul), état « Affiché » /
    « Masqué » / « Masqué : pas de logo », boutons Modifier, Monter, Descendre (désactivés aux extrémités),
    Supprimer (avec `ConfirmDialog`). Un déplacement envoie l'ordre complet (`reorder`) puis rafraîchit la liste.
13. Formulaire (création et modification, dans un `Dialog`) : nom, description courte (compteur 160), lien, case
    « Afficher sur le site », logo (`<input type="file" accept="image/png,image/jpeg,image/webp">`, avec la consigne
    « PNG, JPEG ou WebP, 2 Mo max, fond transparent conseillé ; un SVG doit être converti »), bouton « Retirer le
    logo ». À la création, le logo est envoyé juste après la création (l'id n'existe pas avant).
14. Aperçu du logo sur **fond clair et fond sombre** côte à côte (un logo noir sur transparent disparaît en thème
    sombre : l'admin doit le voir avant de publier).
15. Plus de 5 partenaires visibles : bandeau d'avertissement « L'accueil est pensé pour 5 partenaires au plus. »
    Aucun partenaire : état vide « Aucun partenaire pour l'instant. »
16. Les erreurs de l'API (message de `error.message`) s'affichent dans le formulaire ou sous la liste ; jamais
    d'exception non gérée.

### Tests et gates

17. API unitaire `tests/Unit/Content/PartnerTest.php` : bornes de chaque champ, URLs acceptées / refusées (AC 2),
    `isVisible()`, `moveTo()` négatif refusé.
18. API fonctionnel `tests/Functional/PartnerTest.php` : public ne voit que les visibles, dans l'ordre ; CRUD admin ;
    reorder (y compris id inconnu) ; logo PNG accepté puis `logoUrl` présent ; SVG et fichier texte refusés en 422
    `image_invalid_type` ; > 2 Mo refusé ; 401/403 ; 404 sur id inconnu ; `javascript:` refusé.
19. Front `src/features/content/partners.test.tsx` : gardes de type (`isPartner`, `isAdminPartner`, refus d'un
    objet incomplet), fonctions pures (déplacement dans la liste, libellé d'état, domaine affiché d'une URL,
    avertissement au-delà de 5), appels API via msw (création 201, 422 avec message, reorder), rendu statique de
    l'état vide.
20. `composer gates` (depuis `api/`) et `pnpm gates` (depuis `frontend/`) verts.
21. **Vérification réelle** (navigateur, base de dev) : créer 2 partenaires, envoyer un logo PNG, réordonner,
    masquer, supprimer ; vérifier l'aperçu clair / sombre ; refaire la page avec uBlock Origin activé (listes par
    défaut + Liste FR) et constater que les logos et la page s'affichent. Noter dans le Dev Agent Record.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 17) - Entité `Partner` + tests unitaires.
- [x] **Task 2** (AC 3) - Migration `partner`.
- [x] **Task 3** (AC 4-9) - `PartnerRepositoryInterface` (`Domain/Repository/`) + `DoctrinePartnerRepository`
  (`Infrastructure/Doctrine/`) + câblage `services.yaml` ; `PartnerDraft` + `ManagePartners`
  (`Application/Command/`) ; `PartnerCatalogQuery` (`Application/Query/`, `forVisitors()` / `forAdmin()`).
- [x] **Task 4** (AC 4-10, 18) - `PartnerController` (`Presentation/Controller/`) + tests fonctionnels.
- [x] **Task 5** (AC 11-16) - Front : `src/features/content/partners-api.ts` (types, gardes, `fetchAdminPartners`,
  `savePartner`, `deletePartner`, `reorderPartners`, `uploadPartnerLogo`, `removePartnerLogo`) ;
  `src/features/admin/admin-partners.tsx` ; page `src/app/(admin)/admin/partenaires/page.tsx` ; entrée de menu.
- [x] **Task 6** (AC 19) - Tests front.
- [x] **Task 7** (AC 20, 21) - Gates verts, vérification réelle, Dev Agent Record.

## Dev Notes

### Hors périmètre (stories suivantes)

- Lecture publique côté front (`getPartners()` en ISR), section d'accueil, page `/partenaires`, lien du footer :
  **45.2**. Cette story livre seulement l'endpoint public et sa garde de type.
- Rattachement aux événements : 45.3. Écran OBS / LAN : 45.4.
- Suivi des clics, niveaux, lots partenaires : hors epic.

### Garde-fous d'architecture (`api/CLAUDE.md`)

- **AC-D3** : l'entité ne lit ni horloge ni aléa ; `$now` et l'id viennent de la commande (`ClockInterface`,
  `bin2hex(random_bytes(16))` dans `ManagePartners`, comme les annonces).
- **AC-A3** : `ManagePartners::create()` renvoie l'id (`string`, déjà le cas des annonces) ; aucune méthode
  publique de commande ne renvoie un `array` (gaté par `validateCommandArrayReturns`) ; aucune méthode publique
  d'Application ne renvoie l'entité (gaté par `validateApplicationEntityReturns`) : `PartnerCatalogQuery` renvoie
  des tableaux typés en PHPDoc.
- **AC-P4** : un seul appel Application par action de contrôleur.
- **AC-T5** : noms de tests en camelCase.
- `reorder` : une seule unité de travail, un seul `flush` (charger tous les partenaires, appliquer `moveTo`, sauver
  en une fois). Ajouter au dépôt une méthode `saveAll(list<Partner>)` ou un `flush()` comme
  `AdminAchievementCollectionService`.
- PHPStan max : pas de cast de `mixed` ; narrower les champs du JSON comme `HomeAnnouncementController::draft()`
  (`active` : `true === ($payload['active'] ?? false)`).

### Garde-fous front (`frontend/AGENTS.md`)

- **AC-TS3 / AC-TS4** : pas de `as` sur les réponses ; gardes `isPartner` / `isAdminPartner` dans `partners-api.ts`.
- **AC-API1 / AC-API2** : aucun `apiFetch` dans le composant ; chaque fonction renvoie un résultat typé ou `null`,
  jamais d'exception (reprendre `send` / `errorMessage` / `SaveResult` des annonces ; les factoriser n'est pas
  demandé).
- **AC-API5** : `staleTime: DEFAULT_STALE_TIME` sur le `useQuery`.
- **AC-KEY1** : clé de liste = `partner.id` (la liste se réordonne).
- **AC-CSS2** : couleurs par jetons. Seule exception assumée : les deux tuiles d'aperçu du logo (AC 14) sont
  volontairement fixes (`bg-white` et `bg-neutral-900`), indépendantes du thème, avec un commentaire qui le dit.
- Images : l'hôte du bucket public est déjà dans `images.remotePatterns` (`next.config`), `next/image` marche
  pour un logo envoyé (`home-announcement-modal.tsx:111`). L'aperçu local d'un fichier choisi (blob URL) passe
  par `<img>` avec le commentaire `eslint-disable-next-line @next/next/no-img-element`, comme
  `admin-home-announcements.tsx:325`.

### Pièges connus

- **Type MIME** : `UploadedFile::getMimeType()` lit le contenu (finfo), pas l'extension : un `.png` qui est un SVG
  est bien refusé. Le test le vérifie avec un vrai contenu SVG renommé en `.png`.
- **Clé neuve à chaque upload** (`logo-{hex}`) : sinon le CDN / le navigateur garde l'ancien logo.
- **Création + logo** : deux appels (POST puis upload). Si l'upload échoue, le partenaire existe sans logo : le
  formulaire reste ouvert en mode édition avec l'erreur, pas de création en double au second clic.
- **Windows** : `pnpm` est sous `C:\Users\maste\AppData\Local\nvm\v22.14.0` ; épingler `composer --working-dir` et
  `pnpm --dir` si les gates tournent en parallèle (mémoire « course de dossiers »).
- **Tree partagé** : `git add` avec des chemins explicites, jamais `-A`.

### Repères

- API : `api/src/Content/` (annonces, ci-dessus), `api/src/Shared/Application/Support/PublicMediaUrlResolver.php`,
  `api/src/Shared/Infrastructure/Adapter/MinioStorageInterface.php`,
  `api/src/Shared/Infrastructure/Double/NullMinioStorage.php`,
  `api/src/Community/Presentation/Controller/AdminAchievementCollectionController.php` (`reorder`, lecture de `ids`).
- Front : `src/features/content/home-announcement-api.ts`, `src/features/admin/admin-home-announcements.tsx`,
  `src/components/admin-shell.tsx`, `src/components/ui/{dialog,confirm-dialog,button}.tsx`,
  `src/lib/type-guards.ts`, `src/lib/apiFetch.ts`, `src/lib/query-client.ts`.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- Ultimate context engine analysis completed - comprehensive developer guide created.
- API : entité `Partner` (Content), migration `Version20261025200004`, `ManagePartners` (create, edit, delete,
  reorder en un seul flush via `saveAll`, attachLogo, removeLogo), `PartnerCatalogQuery` (`forVisitors` filtre
  `isVisible()`, `forAdmin` ajoute `active` et `visible`), `PartnerController` (8 routes). Logo : PNG/JPEG/WebP,
  2 Mo, type lu dans le contenu (un SVG renommé en `.png` est refusé, testé) ; clé `content-logos/{id}/logo-{hex}`.
- Front : `partners-api.ts`, `admin-partners.tsx` (monter / descendre, état « Masqué : pas de logo »,
  avertissement au-delà de 5 visibles, aperçu du logo sur tuiles claire et sombre fixes), page
  `/admin/partenaires`, entrée « Partenaires » (icône `Handshake`) après « Annonces ».
- Création puis échec de l'envoi du logo : le formulaire garde l'id créé (`createdId`), le second envoi modifie le
  partenaire au lieu d'en créer un double.
- Gates : `composer gates` vert (3323 tests, base isolée `TEST_TOKEN=_partners`). `pnpm gates` : typecheck, lint,
  1291 tests et build verts avec `NEXT_PUBLIC_APP_URL=http://localhost:3000` (valeur de CI) ; avec le
  `frontend/.env` local (IP du réseau local), 10 tests SEO existants (sitemap, robots, structured-data) échouent,
  sans lien avec la story.
- Base de dev migrée (`partner` + 3 migrations de `develop` en attente).
- **Vérification réelle (AC 21), 2026-10-30, Chrome sur `http://localhost:3000` (session admin de Jean)** : création
  avec logo PNG (objet servi par MinIO, partenaire dans `GET /api/v1/partners`), second partenaire sans logo
  (« Masqué : pas de logo »), monter / descendre (flèches désactivées aux extrémités), masquage (liste publique
  vide), suppression des deux via la confirmation. L'aperçu clair / sombre montre bien qu'un logo noir disparaît sur
  fond sombre ; il disparaît aussi dans la vignette de la liste (fond du thème), comportement attendu.
  Non vérifié par l'agent : l'affichage avec uBlock activé (extension non pilotable), à refaire surtout en 45.2 sur
  les pages publiques.
- Environnement : sur `http://192.168.1.193:3000` l'admin reste en squelette, l'API de dev n'autorisant en CORS que
  `http://localhost:3000` (préexistant, toutes les pages admin).

### File List

- `api/src/Content/Domain/Entity/Partner.php` (nouveau)
- `api/src/Content/Domain/Repository/PartnerRepositoryInterface.php` (nouveau)
- `api/src/Content/Infrastructure/Doctrine/DoctrinePartnerRepository.php` (nouveau)
- `api/src/Content/Application/Command/PartnerDraft.php` (nouveau)
- `api/src/Content/Application/Command/ManagePartners.php` (nouveau)
- `api/src/Content/Application/Query/PartnerCatalogQuery.php` (nouveau)
- `api/src/Content/Presentation/Controller/PartnerController.php` (nouveau)
- `api/migrations/Version20261025200004.php` (nouveau)
- `api/config/services.yaml` (modifié)
- `api/tests/Unit/Content/PartnerTest.php` (nouveau)
- `api/tests/Functional/PartnerTest.php` (nouveau)
- `frontend/src/features/content/partners-api.ts` (nouveau)
- `frontend/src/features/content/partners.test.tsx` (nouveau)
- `frontend/src/features/admin/admin-partners.tsx` (nouveau)
- `frontend/src/app/(admin)/admin/partenaires/page.tsx` (nouveau)
- `frontend/src/components/admin-shell.tsx` (modifié)
