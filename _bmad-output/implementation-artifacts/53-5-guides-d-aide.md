# Story 53.5 - Guides d'aide modifiables

**Status:** in-progress (vérification réelle à faire)
**Epic:** 53 - Mon event, l'espace de l'inscrit (`_bmad-output/planning-artifacts/epics/epic-53-espace-inscrit.md`)
**Date:** 2026-11-11
**Branche:** `feature/epic-53-story-5-guides-d-aide` (empilée sur `feature/epic-53-story-7-trouver-l-espace`, PR #1087)
**Dépend de:** 53.1 (pour la section de l'espace ; les pages `/aide` n'en dépendent pas).

## Story

En tant que joueur,
je veux des explications simples sur ce qui fait une ArchiLAN (les pelles, le Passeur, le blind test,
Minimapperz...),
et en tant qu'orga je veux les rédiger et les corriger depuis l'admin,
afin que personne ne reste avec un « c'est quoi ce truc ? ».

## Contexte (cartographie du 2026-11-11)

- Seule page d'aide : `/aide/archipelago` (`app/(public)/aide/archipelago/page.tsx`), en-tête en dur, étapes en base
  (`GameSelection/Domain/Entity/ArchipelagoGuide.php`, admin `/admin/archipelago-guide`, lecture
  `GET /api/v1/archipelago-guide`). C'est le modèle à suivre.
- Contexte `Content` : `HomeAnnouncement`, `Partner`, `Post`. Pas d'entité de page ou de bloc libre.
- Commande admin à copier : `Content/Application/Command/ManagePartners.php`.

## Décisions

1. **Entité** `Content/Domain/Entity/HelpGuide` : `id`, `slug` (unique, `[a-z0-9-]`, 2 à 60), `title` (1 à 80),
   `summary` (1 à 200, texte brut, sert aux cartes et au SEO), `body` (markdown, 1 à 20 000), `position`,
   `published` (bool), `createdAt`, `updatedAt`.
2. **Slugs réservés** : `archipelago` est refusé (`help_guide_slug_reserved`), la page existante reste la sienne.
   Slug déjà pris → 409 `help_guide_slug_taken`.
3. **Admin** `/admin/aide` : liste ordonnable, création, modification (`MarkdownEditor`, aperçu), publication,
   suppression d'un guide non publié. Entrée dans la navigation admin à côté du guide Archipelago.
4. **Public** :
   - `GET /api/v1/help-guides` (publiés, ordonnés : slug, titre, résumé) et `GET /api/v1/help-guides/{slug}` (404
     si absent ou non publié).
   - `/aide` : index des guides (cartes), le guide Archipelago en tête.
   - `/aide/[slug]` : le guide, metadata (titre, `summary` en description), ISR comme les pages publiques de
     l'epic 34, ajout au sitemap.
5. **Guides de départ** (migration de données, publiés, que Jean relira dans l'admin) : `pelles`, `passeur`,
   `blind-test`, `minimapperz`, `premiere-partie` (de l'inscription au goal : choisir ses jeux, YAML, se connecter,
   BK, release), `diffusion` (consentement, Twitch, écran régie). Textes rédigés à partir du code et des stories
   (epics 41, 52, 41.40, 49, 47), courts, tutoiement, sans jargon non expliqué.
6. **Liens** : constante front des slugs connus ; les cartes de 53.3 pointent vers `/aide/<slug>` ; l'espace a une
   section « Guides » qui liste les guides publiés.

## Critères d'acceptation

1. L'admin crée, modifie, ordonne, publie un guide ; un brouillon n'est visible nulle part côté public.
2. `/aide` liste les guides publiés et le guide Archipelago ; `/aide/<slug>` affiche un guide.
3. Les six guides de départ existent après migration.
4. L'espace inscrit liste les guides, les cartes d'outils pointent vers le leur.
5. Slug réservé ou déjà pris : refus explicite.

## Tests et gates

6. Unitaires : bornes, slug (format, réservé).
7. Fonctionnels : CRUD admin (401 / 403), lectures publiques (brouillon invisible, 404).
8. Front : index, page de guide, admin.
9. `composer gates` et `pnpm gates` verts (`StandardsDocsMatchToolingTest` si la liste des entités est documentée).

## Tasks / Subtasks

- [x] **Task 1** (AC 5, 6) - Entité, règles, migration de schéma.
- [x] **Task 2** (AC 1, 7) - Commande `ManageHelpGuides`, requêtes, contrôleurs.
- [x] **Task 3** (AC 3) - Migration de données des guides de départ (textes relus par Jean dans la PR).
- [x] **Task 4** (AC 1, 2, 8) - Admin `/admin/aide`, pages `/aide` et `/aide/[slug]`, sitemap.
- [x] **Task 5** (AC 4) - Section « Guides » de l'espace, liens des cartes.
- [ ] **Task 6** (AC 9) - Gates, vérification réelle. Gates statiques et unitaires faits ; tests fonctionnels (CI) et vérification réelle à faire.

## Dev Notes

- Le rendu markdown public passe par `Markdown` ; le contenu vient d'un admin, pas besoin du mode `untrusted`,
  mais garder le comportement par défaut sur les liens externes.
- Mettre les textes de départ dans la description de la PR pour relecture.

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5

### Completion Notes List

- **API (contexte Content)** : entité `HelpGuide` (slug `[a-z0-9]+(-[a-z0-9]+)*` de 2 à 60, `archipelago` réservé, titre 80, résumé 200,
  texte 20 000, position, publié), `ManageHelpGuides` (création en dernier, modification, suppression d'un brouillon
  seulement : 409 `help_guide_published`, réordonnancement), slug pris : 409 `help_guide_slug_taken`, `HelpGuideCatalogQuery`,
  `HelpGuideController` : `GET /help-guides`, `GET /help-guides/{slug}` (404 brouillon ou inconnu), CRUD sous
  `/admin/help-guides` (+ `/reorder`).
- **Cache public** : tags `guides` et `guide:<slug>` (`PublicPagesRevalidation::helpGuidesChanged`, ancien et nouveau slug
  au renommage). Pas de tiret dans le tag : `readTags` côté front n'accepte que `[a-z]+`.
- **Migration** `Version20261111100001` : table `help_guide` et les six guides de départ publiés (`premiere-partie`,
  `pelles`, `passeur`, `blind-test`, `minimapperz`, `diffusion`), textes rédigés d'après le code et les stories, à
  relire par Jean dans l'admin.
- **Front public** : `/aide` (index, Archipelago en tête, ISR 300 s), `/aide/[guideSlug]` (metadata, fil d'Ariane
  JSON-LD, 404 sur brouillon), sitemap (`/aide` + guides publiés). Les titres du markdown commencent à h3 (règle
  du composant `Markdown`).
- **Admin** : `/admin/aide`, onglet « Guides d'aide » de **Publications** (plutôt qu'à côté de l'aide Archipelago,
  rangée sous Apworlds) ; liste ordonnable, formulaire avec `MarkdownEditor`, adresse suggérée depuis le titre,
  suppression désactivée tant que le guide est publié.
- **Espace inscrit** : section « Les guides » (Archipelago puis les guides publiés, lien « Tous les guides ») ;
  lien « C'est quoi ? » (`components/help-link.tsx`) sur les cartes partie (première partie), pelles, Passeur,
  blind test, Minimapperz et diffusion, seulement quand le guide est publié (`guideHref`, `HELP_GUIDE_SLUGS`).
- Gates : front typecheck, lint, test (256 suites, 1629 tests), build verts ; API phpstan, cs-fixer, ddd, rector,
  unitaires verts. `HelpGuideTest` fonctionnel non lancé en local (Postgres arrêté).

### File List

- `api/src/Content/Domain/Entity/HelpGuide.php` (nouveau)
- `api/src/Content/Domain/Repository/HelpGuideRepositoryInterface.php` (nouveau)
- `api/src/Content/Infrastructure/Doctrine/DoctrineHelpGuideRepository.php` (nouveau)
- `api/src/Content/Application/Command/HelpGuideDraft.php` (nouveau)
- `api/src/Content/Application/Command/ManageHelpGuides.php` (nouveau)
- `api/src/Content/Application/Query/HelpGuideCatalogQuery.php` (nouveau)
- `api/src/Content/Presentation/Controller/HelpGuideController.php` (nouveau)
- `api/src/Shared/Application/Port/PublicPageCacheInterface.php` (modifié)
- `api/src/Shared/Application/Support/PublicPagesRevalidation.php` (modifié)
- `api/config/services.yaml` (modifié)
- `api/migrations/Version20261111100001.php` (nouveau)
- `api/tests/Unit/Content/HelpGuideTest.php` (nouveau)
- `api/tests/Functional/HelpGuideTest.php` (nouveau)
- `frontend/src/app/(public)/aide/page.tsx` (nouveau)
- `frontend/src/app/(public)/aide/[guideSlug]/page.tsx` (nouveau)
- `frontend/src/app/(admin)/admin/(publications)/aide/page.tsx` (nouveau)
- `frontend/src/app/sitemap.ts`, `sitemap.test.ts` (modifiés)
- `frontend/src/components/help-link.tsx` (nouveau)
- `frontend/src/features/content/help-guides-api.ts`, `help-guide-views.tsx`, `help-guides.test.tsx` (nouveaux)
- `frontend/src/features/admin/admin-help-guides.tsx` (nouveau)
- `frontend/src/features/admin/admin-sections.ts`, `admin-sections.test.tsx` (modifiés)
- `frontend/src/features/registrant-hub/hub-tools.tsx`, `registrant-hub.tsx` (modifiés)
- `frontend/src/features/wallet/blind-test-join-button.tsx`, `minimapperz-code-card.tsx` (modifiés)
- `frontend/src/features/passeur/passeur-notice.tsx` (modifié)
- `frontend/src/features/streaming/broadcast-consent-card.tsx` (modifié)

