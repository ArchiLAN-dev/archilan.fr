# Story 30.38: /communaute reconstruite en hub communautaire (absorbe /classements)

**Status:** review
**Epic:** 30 - Community & account
**Date:** 2026-08-05

## Story

En tant que visiteur qui découvre ArchiLAN (non connecté),
je veux que `/communaute` me montre une communauté vivante - combien nous sommes, qui joue en ce moment,
qui domine les classements, quels succès viennent de tomber, et qui sont les membres,
afin d'avoir envie de rejoindre plutôt que de tomber sur une liste de pseudos.

## Context

`/communaute` est aujourd'hui `CommunityDirectory` : trois onglets (Top joueurs / Récemment actifs /
Mes amis), une recherche, et une grille de cartes minimales (avatar 40px, pseudo, « Niv. X »). C'est une
entrée de nav de premier niveau qui n'affiche rien d'autre.

Trois problèmes structurels :

1. **Redondance.** L'onglet « Top joueurs » recoupe `/classements` (page qui n'est même pas dans la nav),
   « Mes amis » recoupe `/compte/amis`, et le fil d'activité vit à `/compte/activite`.
2. **Richesse inexploitée.** Rien de ce que l'epic 30 a construit n'apparaît : succès, XP/niveaux,
   bannières, frames d'avatar, badges de reconnaissance, kudos, présence.
3. **Zéro valeur pour l'anonyme.** Pour le SEO et le recrutement, la page ne raconte rien.

### Décisions produit (arbitrées avec Jean, 2026-08-05)

| Question | Décision |
|---|---|
| Nature de la page | **Hub composé** : vitrine éditorialisée, pas un annuaire |
| Périmètre | **`/classements` est absorbé** dans le hub et supprimé (redirigé) |
| Audience prioritaire | **Visiteur non connecté** ; le contenu connecté reste secondaire |

Corollaire de « hub composé » + « absorbe classements » : l'annuaire complet (recherche + pagination)
sort du hub vers une page dédiée **`/joueurs`** (l'espace de route existe déjà pour les profils). Le hub
n'en garde qu'un aperçu. Alternative écartée : garder l'annuaire paginé en bas du hub - ça rallonge une
page qui porte déjà le classement complet, et ça retire au hub son caractère de vitrine.

### Ce que l'API sait déjà faire vs les manques

| Bloc du hub | Endpoint | État |
|---|---|---|
| Stats globales (runs, checks, objectifs) | `GET /api/v1/community/stats` | ✅ existe |
| Classements 3 axes + filtre événement | `GET /api/v1/leaderboard` | ✅ existe |
| Aperçu annuaire + recherche | `GET /api/v1/community/directory` | ✅ existe |
| Nombre de membres | - | ❌ **manque** |
| Qui joue **maintenant** (global) | `CommunityPresenceQueryInterface::playing($ids)` prend une liste d'ids | ❌ **manque** (pas de requête globale) |
| Succès récemment débloqués (global) | grants lisibles uniquement par profil | ❌ **manque** |

Ces trois manques sont regroupés dans **un seul nouvel endpoint public** `GET /api/v1/community/overview`,
plutôt que trois appels : le hub fait alors 3 requêtes au total (overview + stats + leaderboard) au lieu de 6.

## Acceptance Criteria

### Backend

1. **Nouvel endpoint public** `GET /api/v1/community/overview`, sans authentification requise
   (`ApiAccessGuard::optionalUser`), `Cache-Control: private, max-age=30` (voir Dev Notes : le payload
   depend du viewer, un cache partage le ferait fuiter), renvoyant :
   `{ data: { memberCount: int, playingNow: [...], recentAchievements: [...] } }`.
2. **`memberCount`** = membres listables (slug non nul, non supprimé) - la même population que
   `DbalAchievementRarityQuery::snapshot()['memberCount']`, pour que « 142 membres » et les pourcentages
   de rareté ne se contredisent pas d'une page à l'autre.
3. **`playingNow`** = jusqu'à 12 membres tenant un slot dans une session `running`, chacun
   `{ slug, displayName, avatarUrl, game: string|null }`.
   **Fuite à éviter :** `game` n'est renseigné que si la session est consultable par le viewer ; sinon
   `null` et l'UI affiche « en jeu » sans préciser quoi. Une run personnelle non publiée ne doit jamais
   révéler son jeu à un anonyme.
   *Livré :* `sessionId` a été retiré du payload - une session `running` n'a aucune page publique vers
   laquelle pointer (`/parties/{id}` est le récap d'une session **terminée**), donc l'exposer n'aurait
   produit qu'un lien mort.
4. **`recentAchievements`** = jusqu'à 8 derniers `community_achievement_grant` (tri `unlocked_at` desc),
   chacun `{ achievementKey, name, imageUrl: string|null, slug, displayName, avatarUrl, unlockedAt }`.
   Restreint aux membres listables.
   *Livré, correction de cadrage :* le brouillon exigeait en plus un filtrage par
   `ProfileVisibility::canSee()`. C'est faux et ça aurait vidé la page. `ProfileVisibility` ne gate que
   le bloc **personnalisation** d'un profil (bio, bannière, liens) - identité, succès, niveau et présence
   sont publics sur `/joueurs/{slug}` pour tout le monde. Pire, `canSee()` traite une absence de ligne
   `community_profile` comme `MEMBERS` (choix délibéré de la story 30.28), donc filtrer dessus aurait
   masqué à un anonyme tout membre n'ayant jamais ouvert son propre profil - c'est-à-dire la majorité.
   La règle retenue est « membre listable » (slug public, non supprimé), celle qu'appliquent déjà
   l'annuaire et les pourcentages de rareté.
5. **Couches respectées** : `CommunityOverviewQuery` en `Community/Application/Query/`, interfaces
   `*QueryInterface` à côté, implémentations DBAL en `Community/Infrastructure/Dbal/`. Le contrôleur ne
   fait qu'un appel Application (AC-P4). Aucun `Connection` dans Application (AC-A2).
6. Tests : unitaires sur `CommunityOverviewQuery` (membre sans carte listable, définition supprimée,
   masquage du jeu pour une session non consultable), fonctionnel sur l'endpoint (200 anonyme, comptage,
   jeu nommé pour une session d'événement public vs masqué pour une run personnelle non publiée).

### Frontend - le hub

7. **`/communaute` est reconstruite** en Server Component qui compose des sections, dans cet ordre :
   1. **En-tête + stats** : titre, accroche, et une barre de compteurs (membres, runs terminées, checks,
      objectifs). *Livré sans le count-up* : le compteur animé démarre à 0, donc le HTML servi au
      crawler aurait annoncé « 0 membres », ce que l'AC 8 interdit. Le widget animé de la home reste
      inchangé (il fetch côté client, le problème ne s'y pose pas).
   2. **En jeu maintenant** : avatars + pseudos des joueurs en session, badge pulsé, lien vers le profil,
      et le jeu quand le viewer a le droit de le connaître. **Section masquée si vide** - un bloc
      « personne ne joue » tue l'effet recherché.
   3. **Classements** : `LeaderboardClient` (3 axes + filtre par événement), déplacé ici tel quel.
   4. **Succès récemment débloqués** : qui a débloqué quoi, avec l'image du succès, lien vers
      `/joueurs/{slug}/succes`. Masquée si vide.
   5. **Membres** : aperçu (12 cartes max) + champ de recherche + lien « Voir tous les membres » vers
      `/joueurs`.
   6. **Rejoindre** : entrées Discord / Twitch / adhésion.
8. **Rendu serveur et SEO.** Les données de la page (stats, overview, leaderboard initial, aperçu
   annuaire) sont récupérées côté serveur ; un anonyme reçoit une page pleine sans JS. `generateMetadata`
   / `buildPageMetadata` avec title, description, `openGraph.title` (AC-NX6).
9. **Dégradation.** Chaque fetch renvoie `null` en échec (AC-API2) ; une section dont la donnée manque
   disparaît, la page ne casse pas et ne 500 pas.
10. **Couche connectée, secondaire.** Si le viewer est connecté, la section Membres montre en premier ses
    amis en ligne ; aucun bloc réservé aux connectés au-dessus de la ligne de flottaison.

### Frontend - annuaire déplacé et nettoyage

11. **`/joueurs`** (nouvelle page index) porte l'annuaire complet : recherche, modes Top / Récemment
    actifs / Mes amis, pagination - c'est-à-dire le comportement actuel de `CommunityDirectory`, avec les
    cartes retravaillées (niveau + barre d'XP, badge en jeu, badges de reconnaissance si dispo).
    *Livré :* la barre d'XP a nécessité d'ajouter `xpIntoLevel` / `xpForNextLevel` aux lignes d'annuaire.
    `CommunityLevelQuery` les calculait déjà et `CommunityDirectory::enrich()` les jetait. Les badges de
    reconnaissance (adhérent / admin) ne sont **pas** livrés : ils demandent une lecture d'adhésion
    active par ligne, que le read model léger de l'annuaire évite volontairement. À traiter à part si
    Jean les veut.
12. **`/classements` est supprimée** ; une redirection permanente `/classements → /communaute` est ajoutée
    dans `next.config.ts`.
13. **Tous les liens entrants sont mis à jour** : `app/(public)/page.tsx:58`,
    `features/community/community-stats-widget.tsx:73`, `features/recap/session-recap-page.tsx:58`.
14. **Sitemap** : `/classements` retiré, `/joueurs` ajouté dans `STATIC_ROUTES`.
15. **Nav** : l'entrée « Communauté » reste inchangée dans `public-shell.tsx` (desktop + mobile) - le hub
    est désormais la destination unique, `/classements` n'est plus orpheline.

### Gates

16. `composer gates` vert côté API, `pnpm gates` vert côté frontend.

## Tasks / Subtasks

- [x] **Task 1** (AC 1-6). Backend : `CommunityOverviewQuery` + `CommunityOverviewController`
  (`GET /api/v1/community/overview`), méthode globale de présence (`playingNow(int $limit)` sur
  `CommunityPresenceQueryInterface` + `DbalCommunityPresenceQuery`), nouvelle
  `RecentAchievementGrantsQueryInterface` + implémentation DBAL, comptage des membres listables.
  Tests unitaires + fonctionnel.
- [x] **Task 2** (AC 7-9). Frontend : `frontend/src/features/community/community-hub.tsx` et ses
  sous-composants (stats, en-jeu, succès récents, aperçu membres, rejoindre) + `community-overview-api.ts`
  (fetch + type guards). Réécriture de `app/(public)/communaute/page.tsx` en Server Component composant
  les sections.
- [x] **Task 3** (AC 7.3, 12, 13). Déplacement du classement dans le hub ; suppression de
  `app/(public)/classements/`, redirect `next.config.ts`, mise à jour des 3 liens entrants.
- [x] **Task 4** (AC 11, 14). Nouvelle page `app/(public)/joueurs/page.tsx` portant l'annuaire ;
  `CommunityDirectory` retravaillé (cartes enrichies) et rebranché dessus ; sitemap mis à jour.
- [x] **Task 5** (AC 10). Couche connectée : amis en ligne en tête de l'aperçu membres.
- [x] **Task 6** (AC 16). `composer gates` + `pnpm gates` verts ; vérification en local des deux pages,
  connecté et déconnecté.

## Dev Notes

- **Ne pas jeter ce qui marche.** `LeaderboardClient`, `CommunityDirectory`, `CommunityStatsWidget`
  (compteur animé), `AvatarFrame`, `PlayerBadges` sont réutilisés. « De zéro » vaut pour la **composition**
  de la page, pas pour les briques.
- **`CommunityStatsWidget` est aussi sur la home** (`app/(public)/page.tsx:287`) et son bouton pointe vers
  `/classements` - le changer casserait la home si le lien n'est pas mis à jour en même temps (AC 13).
- **Présence.** `DbalCommunityPresenceQuery::playing()` couvre déjà les deux formes de session (session
  d'événement via `registration`, run personnelle où `slot.registration_id` **est** l'id utilisateur) ;
  la variante globale reprend les mêmes jointures sans le filtre `IN (:ids)`, plus un `LIMIT`.
- **Visibilité des recaps.** Pour décider si `sessionId` est exposable (AC 3), s'appuyer sur
  `ViewableRecapsQuery::forViewer()` - c'est déjà ce que fait `CommunityFeedQuery` pour les liens de recap
  (story 32.20). Ne pas réimplémenter la règle côté front.
- **Cache-Control.** `/community/stats` est déjà `public, max-age=60`. L'overview contient de la présence
  temps réel : 30 s max, et pas de cache partagé si le payload dépend du viewer (`playingNow` /
  `recentAchievements` sont filtrés par visibilité) - sur une réponse dépendant du viewer, préférer
  `private` ou pas de cache plutôt qu'un `public` qui ferait fuiter le payload d'un membre vers un anonyme.
  **À trancher à l'implémentation** : soit deux variantes (anonyme cacheable / connecté non cacheable),
  soit `private, max-age=30` partout. Par défaut : `private`.
- **Pas de fil d'activité global.** `/api/v1/community/feed` est amis-only et authentifié ; le hub ne
  l'utilise pas. Les « succès récents » jouent ce rôle côté vitrine sans nouvel endpoint de feed public.
- **`/joueurs` index.** Attention au conflit de route : `app/(public)/joueurs/[playerSlug]/` existe,
  ajouter `joueurs/page.tsx` à côté est valide en App Router.
- Effort estimé : ~1 jour backend + 1,5 jour frontend.

### Project Structure Notes

- Nouveau (API) : `src/Community/Application/Query/CommunityOverviewQuery.php`,
  `RecentAchievementGrantsQueryInterface.php`, `src/Community/Infrastructure/Dbal/DbalRecentAchievementGrantsQuery.php`,
  `src/Community/Presentation/Controller/CommunityOverviewController.php`.
- Modifié (API) : `CommunityPresenceQueryInterface` + `DbalCommunityPresenceQuery` (méthode globale).
- Nouveau (front) : `features/community/community-hub.tsx` (+ sous-composants),
  `features/community/community-overview-api.ts`, `app/(public)/joueurs/page.tsx`.
- Réécrit (front) : `app/(public)/communaute/page.tsx`, `features/community/community-directory.tsx`.
- Supprimé (front) : `app/(public)/classements/page.tsx`.
- Modifié (front) : `next.config.ts` (redirect), `app/sitemap.ts`, `app/(public)/page.tsx`,
  `features/community/community-stats-widget.tsx`, `features/recap/session-recap-page.tsx`.

### References

- [Source: _bmad-output/implementation-artifacts/30-15-directory.md (annuaire actuel)]
- [Source: _bmad-output/implementation-artifacts/30-14-presence.md (présence « en jeu »)]
- [Source: _bmad-output/implementation-artifacts/30-31-achievements-recent-on-profile-and-catalogue-page.md (rareté, catalogue)]
- [Source: api/src/Community/Application/Query/CommunityFeedQuery.php (filtrage visibilité + recaps consultables)]
- [Source: api/src/Community/Infrastructure/Dbal/DbalAchievementRarityQuery.php (définition « membre listable »)]

## Dev Agent Record

### Agent Model Used

claude-opus-5 (Claude Code).

### Completion Notes List

- `/communaute` est un hub composé, rendu serveur : stats (dont le nombre de membres), en jeu maintenant,
  classements (absorbés), succès récemment débloqués, aperçu membres + recherche, entrées Discord/Twitch/
  adhésion. Les deux sections live disparaissent quand elles sont vides.
- Nouvel endpoint `GET /api/v1/community/overview` (`private, max-age=30`) : une lecture pour les trois
  données que rien d'autre ne servait.
- **Correction de cadrage assumée en cours de route** : le brouillon voulait filtrer les deux listes par
  `ProfileVisibility::canSee()`. Vérification faite dans `CommunityProfileView`, cette règle ne gate que
  le bloc personnalisation d'un profil, et traite une absence de ligne `community_profile` comme
  `MEMBERS` - l'appliquer aurait masqué à un anonyme la majorité des membres, sur la page précisément
  conçue pour lui. Remplacée par la règle « membre listable », alignée sur l'annuaire (AC 4).
- Fuite fermée : `SessionRecapAudience` n'était atteignable en lot que via `ViewableRecapsQuery`, qui
  exige une session **terminée** avec récap. Ajout de `ViewableSessionsQuery` (même délégation, tout
  statut) pour que « en jeu maintenant » puisse taire le jeu d'une run non publiée sans dupliquer la
  règle d'audience en SQL.
- `xpIntoLevel` / `xpForNextLevel` ajoutés aux lignes d'annuaire : `CommunityLevelQuery` les calculait
  déjà, `CommunityDirectory::enrich()` les jetait, et sans eux les cartes ne pouvaient pas dessiner de
  barre de progression.
- `/classements` supprimée, redirigée en 308 vers `/communaute` ; les trois liens entrants (home, widget
  de stats, page de récap) et le sitemap suivent.
- Gates : API `phpstan` / `php-cs-fixer` / `app:architecture:ddd` / `rector --dry-run` verts,
  `phpunit` 1731 tests verts (run isolé) ; frontend `typecheck` / `lint` / `test` (348) / `build` verts.
  Vérifié en local : endpoint 200 avec données réelles, hub rendu côté serveur (compteur, cartes, succès),
  `/joueurs` 200, `/classements` 308.
- **Observation pour Jean, non traitée** : sur les données réelles, quatre grants partagent l'horodatage
  du backfill (2026-07-04T13:45), donc « succès récemment débloqués » affiche trois fois « Premier
  objectif » et quatre tuiles pour le même membre. La section est fidèle aux données ; si l'effet vitrine
  prime, il faudrait dédupliquer par succès ou plafonner par membre - c'est un choix produit, pas un bug.

### File List

- `api/src/Community/Application/Query/CommunityOverviewQuery.php` (new)
- `api/src/Community/Application/Query/RecentAchievementGrantsQueryInterface.php` (new)
- `api/src/Community/Infrastructure/Dbal/DbalRecentAchievementGrantsQuery.php` (new)
- `api/src/Community/Presentation/Controller/CommunityOverviewController.php` (new)
- `api/src/Sessions/Application/Query/ViewableSessionsQuery.php` (new)
- `api/src/Community/Application/Query/CommunityPresenceQueryInterface.php`,
  `api/src/Community/Infrastructure/Dbal/DbalCommunityPresenceQuery.php` (`playingNow`)
- `api/src/Community/Application/Query/CommunityDirectoryQueryInterface.php`,
  `api/src/Community/Infrastructure/Dbal/DbalCommunityDirectoryQuery.php` (`listableMemberCount`)
- `api/src/Community/Application/Query/CommunityDirectory.php` (xpIntoLevel / xpForNextLevel)
- `api/config/services.yaml` (wiring)
- `api/tests/Unit/Community/CommunityOverviewQueryTest.php`,
  `api/tests/Functional/CommunityOverviewTest.php` (new)
- `frontend/src/app/(public)/communaute/page.tsx` (rewritten)
- `frontend/src/app/(public)/joueurs/page.tsx` (new)
- `frontend/src/app/(public)/classements/page.tsx` (deleted)
- `frontend/src/features/community/community-hub.tsx`, `community-hub-stats.tsx`,
  `community-members-preview.tsx`, `member-card.tsx`, `member-avatar.tsx`,
  `community-overview-api.ts`, `community-overview-api.test.ts` (new)
- `frontend/src/features/community/community-directory.tsx` (reworked cards + initialSearch)
- `frontend/src/features/community/community-directory-api.ts` (server-side fetch + XP fields)
- `frontend/next.config.ts` (redirect), `frontend/src/app/sitemap.ts`,
  `frontend/src/app/(public)/page.tsx`, `frontend/src/features/community/community-stats-widget.tsx`,
  `frontend/src/features/recap/session-recap-page.tsx` (inbound links)

### Change Log

| Date       | Change |
|------------|--------|
| 2026-08-05 | Créée (draft). `/communaute` refaite en hub composé orienté visiteur non connecté ; absorbe `/classements` (supprimée + redirigée) ; l'annuaire complet part sur `/joueurs` ; un nouvel endpoint public `/community/overview` couvre les trois manques (nombre de membres, présence globale, succès récents). |
| 2026-08-05 | Implémentée. Endpoint overview + `ViewableSessionsQuery` + présence globale + comptage membres ; hub serveur en 6 sections, `/joueurs` créée, `/classements` supprimée et redirigée en 308, liens et sitemap suivis. Filtrage `ProfileVisibility` abandonné après vérification (aurait vidé la page pour un anonyme) au profit de la règle « membre listable ». Gates API + frontend verts, vérifié en local. Status → review. |
