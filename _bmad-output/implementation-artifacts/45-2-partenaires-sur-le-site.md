# Story 45.2 - Les partenaires sur le site : accueil, page dédiée, footer

**Status:** review
**Epic:** 45 - Mettre en avant nos partenaires (`_bmad-output/planning-artifacts/epics/epic-45-partenaires.md`)
**Date:** 2026-10-30
**Branche:** `feature/epic-45-story-2-partenaires-site` (partie de `feature/epic-45-story-1-partenaires-admin` ;
PR à recibler sur `develop` après le merge de la #953, ne pas supprimer la branche 45.1 avant)
**Dépend de:** 45.1 (`GET /api/v1/partners`, `PartnerCatalogQuery`, `ManagePartners`)

## Story

En tant que visiteur,
je veux voir qui soutient ArchiLAN sur l'accueil et sur une page dédiée,
afin de connaître les partenaires de l'association et d'aller vers eux.

## Contexte (cartographie du 2026-10-30)

- **Lecture publique** : `GET /api/v1/partners` (45.1) renvoie `[{id, name, description, url, logoUrl}]`, seulement
  les partenaires affichés et avec logo, dans l'ordre de l'admin.
- **Accueil** : `src/app/(public)/page.tsx` (ISR `revalidate = 300`, lectures en parallèle dans `Promise.all`),
  sections dans `src/features/home/home-sections.tsx` (`Eyebrow`, `SectionTitle`, `MoreLink` privés au fichier ;
  `HomeAssociation` ligne ~366). Lectures serveur dans `src/features/home/home-api.ts` (`getJson(path, tags)`,
  `fetch(..., { next: { revalidate: 300, tags } })`, repli silencieux).
- **Invalidation à l'écriture (story 34.10)** : `PublicPagesRevalidation` (`api/src/Shared/Application/Support/`)
  met en file `RevalidatePublicPagesJob(tags)` après l'écriture ; le front (`POST /api/revalidate`,
  `src/lib/revalidate-request.ts`) accepte les tags `[a-z]+(:id)?`. Tags existants : `events`, `posts`
  (`PublicPageCacheInterface`). Test modèle : `api/tests/Functional/PublicPagesRevalidationTest.php`
  (`InMemoryTransport`, `queuedTags()`).
- **Footer** : `src/components/public-shell.tsx` (`"use client"`, lignes ~334-382), rendu par
  `src/app/(public)/layout.tsx` qui lit déjà `fetchDiscordStats()` côté serveur et passe `discord` en prop.
- **Sitemap** : `src/app/sitemap.ts`, `STATIC_ROUTES`.
- **Page publique modèle** : `src/app/(public)/mentions-legales/page.tsx` (`buildPageMetadata`, `max-w-reading`).
- **Contact** : `mailto:contact@archilan.fr` (mentions légales, confidentialité).

## Décisions

1. **Logos sur une pastille blanche fixe**, quel que soit le thème : un logo de partenaire est presque toujours
   dessiné pour un fond clair, et un logo sombre disparaissait sur le fond sombre (constaté en 45.1). La pastille est
   une couleur fixe assumée (commentaire dans le code), pas un jeton de thème.
2. **Une seule carte partagée** `PartnerCard` (`src/features/content/partner-card.tsx`) pour l'accueil et
   `/partenaires`, lien sortant `target="_blank" rel="sponsored noopener noreferrer"`.
3. **Tag `partners`** : chaque écriture de `ManagePartners` (création, modification, ordre, suppression, logo) met en
   file la revalidation ; les lectures front portent le tag. Une modif admin apparaît donc à la visite suivante.
4. **Footer** : lien texte « Partenaires », affiché seulement s'il y a au moins un partenaire visible. Le layout
   public lit la liste (même lecture taguée, mise en cache) et passe `hasPartners` au shell.
5. **`/partenaires` existe toujours** (dans le sitemap) : sans partenaire, elle garde son texte et l'invitation à
   devenir partenaire.
6. **Noms neutres face aux anti-pub** : ids `partenaires-heading`, classes utilitaires seulement, aucun `sponsor`,
   `ad`, `banner`.

## Critères d'acceptation

1. API : `PublicPageCacheInterface::PARTNERS = 'partners'`, `PublicPagesRevalidation::partnersChanged()` ;
   `ManagePartners` l'appelle après chaque écriture réussie, jamais sur un refus (422/404).
2. Front : `PARTNERS_TAG` et `getPartners()` (lecture serveur, `revalidate: 300`, tag `partners`, `[]` en cas
   d'échec ou de réponse invalide) dans `partners-api.ts`.
3. Accueil : section « Ils soutiennent l'association » juste après `HomeAssociation` : pour chaque partenaire, logo,
   nom, description, lien ; lien « Tous nos partenaires » vers `/partenaires`. Rien (ni titre) sans partenaire.
4. Page `/partenaires` : titre, phrase sur ce que les partenaires apportent, liste des cartes (ou phrase « pas encore
   de partenaire »), encart « Devenir partenaire » avec `mailto:contact@archilan.fr`. Metadata via
   `buildPageMetadata` (`path: "/partenaires"`), ISR 300 s, entrée dans `STATIC_ROUTES` du sitemap.
5. Footer : lien « Partenaires » après « Actualités » quand `hasPartners`, absent sinon.
6. Mise en page correcte de 1 à 5 partenaires, du mobile (une colonne) au bureau.
7. Tests : API (revalidation sur création, ordre, logo ; rien sur un refus) ; front (garde et repli de
   `getPartners`, rendu de la section vide / remplie, attributs du lien, page `/partenaires` vide et remplie, lien
   du footer selon `hasPartners`).
8. `composer gates` et `pnpm gates` verts. Vérification réelle dans le navigateur (accueil, `/partenaires`, footer,
   thème clair et sombre, mobile).

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 7) - API : tag `partners`, `partnersChanged()`, appels dans `ManagePartners`, tests.
- [x] **Task 2** (AC 2) - `getPartners()` + `PARTNERS_TAG`.
- [x] **Task 3** (AC 3, 6) - `PartnerCard`, section d'accueil, branchement dans `page.tsx`.
- [x] **Task 4** (AC 4) - Page `/partenaires`, sitemap.
- [x] **Task 5** (AC 5) - Footer conditionnel (layout + shell).
- [x] **Task 6** (AC 7, 8) - Tests, gates, vérification réelle.

## Dev Notes

- Hors périmètre : partenaires d'un événement (45.3), écran OBS / LAN (45.4).
- Le layout public lit la liste à chaque rendu de page publique : même requête que l'accueil, dédupliquée et mise en
  cache par Next (même URL, même tag), pas de coût supplémentaire notable.
- Gates front en local : lancer avec `NEXT_PUBLIC_APP_URL=http://localhost:3000` (le `.env` local pointe sur l'IP du
  réseau, ce qui fait échouer 10 tests SEO existants, voir 45.1).

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- API : tag `partners` (`PublicPageCacheInterface::PARTNERS`), `PublicPagesRevalidation::partnersChanged()`, appelé
  après chaque écriture réussie de `ManagePartners` (6 méthodes) ; rien sur un refus. Tests dans
  `PublicPagesRevalidationTest` (le client de test redémarre le kernel à chaque requête : la file est lue après
  chaque requête). Description de `app:frontend:revalidate` complétée.
- Front : `getPartners()` + `PARTNERS_TAG` ; `PartnerCard` / `PartnerGrid` (logo sur pastille blanche fixe, lien
  `sponsored`) ; `HomePartners` après `HomeAssociation` ; page `/partenaires` (ISR 300 s, invitation
  `mailto:contact@archilan.fr`) ; sitemap (test du nombre de routes statiques passé de 14 à 15) ; lien du footer
  extrait dans `PartnersFooterLink` (testable sans le shell), piloté par `hasPartners` lu dans le layout public.
- Gates : `composer gates` vert ; `pnpm gates` vert (1296 tests, build) avec `NEXT_PUBLIC_APP_URL=http://localhost:3000`.
- Vérification réelle (Chrome, `localhost:3000`, 2 partenaires de test créés puis supprimés) : section d'accueil
  après l'association avec « Tous nos partenaires », page `/partenaires` remplie et vide (invitation présente),
  lien « Partenaires » dans le footer.
- Limites de la vérification locale :
  - logos non affichés en local : Next 16 refuse d'optimiser une image servie depuis une IP locale
    (`dangerouslyAllowLocalIP`), MinIO étant sur `http://localhost:9000`. Les couvertures d'événements existantes
    renvoient le même 400 ; en prod les médias passent par le domaine public https.
  - revalidation par tag désactivée en local (`FRONTEND_INTERNAL_URL` / `REVALIDATE_SECRET` vides) : une modif admin
    apparaît après 5 min ou un rechargement forcé.
  - le site public n'a pas de thème clair à vérifier ; vue mobile non simulée (fenêtre non redimensionnable),
    grille mobile-first (1, 2 puis 3 colonnes) ; uBlock non vérifié.

### File List

- `api/src/Shared/Application/Port/PublicPageCacheInterface.php` (modifié)
- `api/src/Shared/Application/Support/PublicPagesRevalidation.php` (modifié)
- `api/src/Shared/Presentation/Command/RevalidateFrontendCommand.php` (modifié)
- `api/src/Content/Application/Command/ManagePartners.php` (modifié)
- `api/tests/Functional/PublicPagesRevalidationTest.php` (modifié)
- `frontend/src/features/content/partners-api.ts` (modifié)
- `frontend/src/features/content/partner-card.tsx` (nouveau)
- `frontend/src/features/content/partners.test.tsx` (modifié)
- `frontend/src/features/home/home-sections.tsx` (modifié)
- `frontend/src/app/(public)/page.tsx` (modifié)
- `frontend/src/app/(public)/partenaires/page.tsx` (nouveau)
- `frontend/src/app/(public)/layout.tsx` (modifié)
- `frontend/src/components/public-shell.tsx` (modifié)
- `frontend/src/app/sitemap.ts` (modifié)
- `frontend/src/app/sitemap.test.ts` (modifié)
