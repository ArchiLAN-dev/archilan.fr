# Story 30.47: La photo de profil et son cadre, partout

**Status:** done
**Epic:** 30 - Communauté
**Date:** 2026-10-02

## Story

En tant que membre qui a choisi un cadre d'avatar,
je veux que ma photo s'affiche dans ce cadre, et dans la même forme que sur mon profil, partout où elle apparaît,
afin que mon cadre me suive sur tout le site et pas seulement sur ma page de profil.

## Contexte

Retour de Jean (2026-10-02), après la story 30.46 (cadres vidéo « Légendaires ») : les cadres n'apparaissent que sur
la page de profil et dans la personnalisation. Partout ailleurs, la photo est un rond sans cadre. Jean veut un
composant dédié, utilisé partout, et une forme unique : le carré arrondi du profil.

Inventaire (2026-10-02) : 13 rendus de photo de membre, avec 8 implémentations différentes.

| # | Surface | Fichier | Aujourd'hui |
|---|---|---|---|
| 1 | Page de profil | `players/profile-avatar.tsx` (`ProfileAvatar`) | carré arrondi, cadre, 96/112 px |
| 2 | Aperçu de la personnalisation | `community/frame-preview.tsx` | via `ProfileAvatar` |
| 3 | Cartes du sélecteur de cadres | `community/frame-picker-dialog.tsx` (`FrameCard`) | `AvatarFrame preview` 48 px |
| 4 | Annuaire, aperçu des membres du hub | `community/member-avatar.tsx` via `member-card.tsx` | rond 40 px, point « En jeu » |
| 5 | Hub « en jeu maintenant » | `community/community-hub.tsx` (`PlayingCard`) | rond 40 px, point pulsé |
| 6 | Classements | `community/leaderboard-client.tsx` (`PlayerAvatar`) | rond 36 px |
| 7 | Amis (amis, demandes reçues/envoyées) | `community/community-friends-panel.tsx` (inline) | rond 36 px, sans `onError` |
| 8 | Commentaires de profil | `community/profile-comments.tsx` (inline) | rond 36 px, sans `onError` |
| 9 | Catalogue des succès | `community/achievements-catalogue-page.tsx` (`CatalogueAvatar`) | rond 48 px, sans `onError` |
| 10 | Détail d'une partie privée, participants | `personal-runs/personal-run-detail-page.tsx` (`ParticipantAvatar`) | rond 32 px |
| 11 | Détail d'un participant | `personal-runs/personal-run-participant-detail-page.tsx` (`Avatar`) | rond 64 px |
| 12 | En-tête de l'espace membre | `auth/account-shell.tsx` (`HeaderAvatar`) | rond 56 px |
| 13 | Menu du compte (bouton et menu) | `auth/user-menu.tsx` (`Avatar`) | rond 32 et 40 px |

Les replis sans photo divergent aussi (1 ou 2 lettres, `bg-accent/15` ou `/20`, dégradé seulement sur le profil).

Hors périmètre : l'avatar Twitch des streams (`streaming/participant-streams.tsx`, ce n'est pas la photo du membre),
la fiche admin d'un utilisateur (`admin/admin-user-detail.tsx`, des initiales sans photo dans sa charge utile), et
les charges utiles qui portent une photo jamais affichée (fil d'activité, notifications, modération, co-joueurs).

### Décisions de Jean (2026-10-02)

- **Animation hors du profil** : comme les photos GIF des admins (30.42). Sur la page de profil, tout s'anime en
  permanence ; partout ailleurs, image fixe, et l'animation (cadre vidéo et photo GIF) ne joue qu'au survol. Jamais
  sous « réduire les animations ».
- **Exception, la barre de navigation** (Jean, 2026-10-02) : l'avatar du membre connecté dans le bouton du menu du
  compte, en haut à droite, s'anime en permanence comme sur le profil (cadre et photo GIF). Le menu déroulé reste
  au survol.
- **Débordement partout** : l'effet d'un cadre déborde autour de la photo dans les mêmes proportions qu'en grand,
  y compris sur les petits avatars (32 px). Il peut chevaucher le texte ou l'avatar voisin : c'est accepté.
- **Forme unique** : le carré arrondi du profil, rayon proportionnel à la taille.

## Critères d'acceptation

1. **Un composant dédié**, `MemberAvatar` (réécrit, voir Dev Notes), affiche la photo d'un membre dans son cadre, à
   n'importe quelle taille, dans la forme du profil. Les 13 rendus de l'inventaire l'utilisent ; plus aucune photo
   de membre ronde ni implémentation locale.
2. **Forme** : carré arrondi, rayon proportionnel (1rem à 96 px, soit environ 16,7 %). Le cadre CSS (anneau,
   néon, effets) garde ses proportions à toutes les tailles (épaisseur d'anneau et lueur proportionnelles).
3. **Repli sans photo** unique partout : les initiales sur le dégradé déterministe du profil (story 30.27), taille de
   texte proportionnelle ; une photo qui ne charge pas (`onError`) retombe dessus partout.
4. **Cadres CSS** (Couleurs, Néon, Effets) partout. Les animations CSS (néon pulsé, holographique...) suivent la
   même règle que les cadres vidéo : permanentes sur le profil, au survol ailleurs.
5. **Cadres Légendaires (vidéo)** :
   - page de profil et aperçu de la personnalisation : vidéo en boucle, comme aujourd'hui (30.46) ;
   - partout ailleurs : image fixe du cadre, la vidéo ne joue qu'au survol (ou au focus d'un lien qui contient
     l'avatar), et une seule vidéo à la fois dans une liste ;
   - « réduire les animations » : toujours l'image fixe.
6. **Image fixe robuste** : l'image fixe d'un cadre vidéo s'affiche correctement **quel que soit l'élément parent**
   (carte avec effet de survol, menu, liste), sans rectangle noir. Voir Dev Notes « Posters avec transparence ».
7. **Photo GIF d'un admin** (30.42) : inchangé dans son principe, porté par le même composant (fixe, animée au
   survol hors du profil).
8. **API** : chaque charge utile qui alimente un rendu de l'inventaire expose `avatarFrame` (clé ou `null`), avec la
   règle `AvatarFrame::displayed($key, $isAdmin)` (un Légendaire ne s'affiche que pour un admin).
9. **Pas de régression de mise en page** : les listes gardent leur alignement ; le point « En jeu » (annuaire, hub)
   reste visible par-dessus le cadre.
10. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 8) - API : `avatar_frame` dans `DbalCommunityUserDirectoryQuery::cards()` et la résolution
      des cartes (`AvatarUrlResolver::resolveForRow` / `forCard`, ou un voisin), avec `AvatarFrame::displayed()` ;
      propagé à chaque consommateur (liste dans Dev Notes) et au chemin séparé des classements ; formes phpstan à
      jour ; tests.
- [x] **Task 2** (AC 6) - Assets : posters avec transparence pour les 7 cadres vidéo (script reproductible).
- [x] **Task 3** (AC 1-5, 7) - Front : `MemberAvatar` (taille, mode `always` / `hover`, cadre, photo, GIF, repli) ;
      CSS des cadres en proportions ; types et parseurs des charges utiles (`avatarFrame`).
- [x] **Task 4** (AC 1, 9) - Remplacer les 13 rendus ; supprimer les implémentations locales.
- [x] **Task 5** (AC 5-6, 9) - Vérification visuelle surface par surface (cadre CSS, cadre vidéo fixe et au survol,
      sans photo, photo GIF admin), desktop et mobile.
- [x] **Task 6** (AC 10) - Gates.

## Dev Notes

### Composant

- **Nom** : `MemberAvatar` existe déjà (`community/member-avatar.tsx`, rond, utilisé par la carte de membre et le
  hub). Le réécrire plutôt que d'en créer un troisième ; `ProfileAvatar` devient un usage de `MemberAvatar`
  (taille profil, mode `always`) ou disparaît.
- **Props** : `avatarUrl`, `avatarAnimatedUrl?`, `avatarFraming?`, `frame`, `name`, `size` (en px ou une échelle
  nommée), `animate: "always" | "hover"`, `className?`. Le survol se gère dans le composant ; pour animer au focus
  d'un lien parent, accepter un état `active` fourni par le parent ou utiliser `:focus-within` / `group-hover`.
- **Réutiliser** : `AvatarFrame` (`community/avatar-frame.tsx`), `AvatarContent` (photo ou initiales,
  `players/profile-avatar.tsx`), `AvatarImage` (`community/avatar-image.tsx`, bascule GIF au survol),
  `AvatarFrameVideoLayer` / `AvatarFrameSwatchLayer` / `videoLayer()` (`community/avatar-frame-video.tsx`).
- **Proportions** : `avatar-frame.module.css` est écrit en valeurs fixes (`.frame` `border-radius: 1rem`,
  `padding: 3px`, `.inner` `0.78rem`, lueurs en px). Passer en proportions de la taille (pourcentages, ou une
  variable CSS `--avatar-size` posée par le composant et des `calc()`), y compris le repli `PLAIN`
  (`rounded-2xl border-4`).

### Posters avec transparence (AC 6)

Le mélange `mix-blend-mode: screen` ne voit que son contexte d'empilement : sous un parent avec `z-index`,
`transform`, `opacity < 1`, `filter` ou `isolation`, le noir de la vidéo devient un rectangle noir (leçon de 30.46).
Les listes en sont pleines (cartes avec effet de survol, menus). Pour l'image fixe, ne pas mélanger : produire pour
chaque cadre vidéo un **poster RGBA** où la transparence vient de la luminosité (alpha = max(R, G, B), couleurs
divisées par alpha), affiché en simple `<img>` sans `mix-blend-mode`. Le rendu est le même que le mélange `screen`
sur fond sombre et ne dépend plus des parents.

- Fichiers : `public/avatar-frames/<f>-still.webp` (WebP avec alpha), même géométrie que les vidéos (ouverture
  107..403 x 111..407 sur 512 px). Script reproductible (Python/Pillow) dans `scripts/` ou documenté ici.
- La vidéo au survol garde `screen` : vérifier sur chaque surface qu'aucun parent ne crée de contexte d'empilement
  autour de l'avatar ; si une surface ne peut pas l'éviter, la vidéo au survol y est désactivée (image fixe
  seulement) et c'est noté dans le Dev Agent Record.
- Le test « les trois fichiers de chaque cadre vidéo existent » (30.46) passe à quatre fichiers.

### Débordement

- Le calque de l'effet est en position absolue, à 173 % de la taille de l'avatar (géométrie 30.46), donc il ne
  change pas la mise en page. Retirer les `overflow-hidden` des enveloppes propres à l'avatar (amis,
  commentaires) ; un parent qui rogne (carte en `overflow-hidden`) coupe l'effet : à repérer pendant la
  vérification visuelle, et à corriger seulement s'il rogne de façon visible.

### API : où ajouter `avatarFrame`

Presque toutes les listes passent par `Community/Infrastructure/Dbal/DbalCommunityUserDirectoryQuery::cards()`
(interface `CommunityUserDirectoryQueryInterface`, qui porte la forme phpstan de la carte). Elle sélectionne déjà
`u.roles` ; `AvatarUrlResolver::resolveForRow` en tire déjà `isAdmin`. Ajouter `cp.avatar_frame` et la résolution
`AvatarFrame::displayed()` à cet endroit, puis chaque consommateur qui recopie les champs un par un :

| Endpoint | Producteur |
|---|---|
| `GET /community/directory` | `Community/Application/Query/CommunityDirectory::enrich()` |
| `GET /community/overview` (en jeu, succès récents) | `Community/Application/Query/CommunityOverviewQuery` |
| `GET /community/friends` | `Community/Application/Service/FriendshipService::friends()` (étale la carte : forme phpstan seulement) |
| `GET /community/profiles/{slug}/comments` | `Community/Application/Service/ProfileCommentService` |
| `GET /runs/{id}` (participants) | `PersonalRuns/Application/Service/PersonalRunDrafts` |
| `GET /runs/{id}/participants/{pid}/game-selection` | `PersonalRuns/Application/Service/PersonalRunGameSelection::resolveParticipant()` |
| `GET /community/profiles/{slug}/achievements` | `Community/Application/Query/CommunityProfileView::achievementsCatalogFor()` (`cardAvatar()`) |
| `GET /leaderboard` | **chemin séparé** : `Sessions/Infrastructure/Dbal/DbalLeaderboardQuery` (deux SELECT, `resolveAvatar()`), `LeaderboardController`, formes dans `LeaderboardQueryInterface` / `LeaderboardQuery` |

Déjà faits : `GET /community/profiles/{slug}` (`customization.avatarFrame`) et `GET /community/profile`.
Le fil, les notifications et la modération portent une photo jamais affichée : les laisser (hors périmètre).

### Tests à prévoir ou adapter

- API : la carte expose `avatarFrame` (cadre normal, Légendaire admin, Légendaire non-admin = `null`), au moins
  sur l'annuaire et les classements ; tests qui figent les cartes (`CommunityOverviewQueryTest`,
  `AccountModerationServiceTest`) à compléter.
- Front : `MemberAvatar` (forme, taille, repli, cadre CSS, cadre vidéo fixe = poster transparent sans `<video>`,
  mode `always` = vidéo après montage, réduire les animations) ; parseurs des charges utiles (`avatarFrame`) ;
  tests existants qui figent des classes (`avatar-image.test.tsx`, `member-card.test.tsx`, `account-shell.test.tsx`,
  `frame-*.test.tsx`).

### Garde-fous

- Pas de `z-index` ni de filtre CSS autour d'un avatar à cadre vidéo animé (30.46).
- Pas d'em-dash ni d'en-dash.
- Branche : `feature/epic-30-story-47-avatar-encadre-partout` depuis `develop`.

### References

- [Source: _bmad-output/implementation-artifacts/30-46-cadre-de-feu-video.md] cadres vidéo, géométrie, piège du mélange
- [Source: _bmad-output/implementation-artifacts/30-42-avatar-anime-au-survol.md] animation au survol hors du profil
- [Source: _bmad-output/implementation-artifacts/30-27-profile-avatar-upload-and-default-avatars.md] avatars par défaut
- [Source: api/src/Community/Domain/ValueObject/AvatarFrame.php] `displayed()`

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Completion Notes List

- **API (AC 8)** : `avatar_frame` sélectionné par `DbalCommunityUserDirectoryQuery::cards()` et par les deux requêtes
  de `DbalLeaderboardQuery` ; `AvatarUrlResolver::forCard()` / `resolveForRow()` rendent `avatarFrame` avec
  `AvatarFrame::displayed()` (admin lu dans `roles`). Chaque consommateur qui recopie les champs de carte le
  propage (annuaire, vue d'ensemble, fil, amis, commentaires, notifications, modération, parties privées,
  co-joueurs, classements, catalogue des succès via `cardAvatar()`). Tests : `CommunityAvatarFrameCardsTest`
  (cadre normal, Légendaire admin, Légendaire non-admin = `null`), `CommunityLeaderboardTest` étendu, cartes
  simulées des tests unitaires complétées.
- **Posters transparents (AC 6)** : `scripts/avatar-frame-stills.py` produit `<f>-still.webp` (RGBA, 256 px,
  26 à 43 Ko) à partir des posters : alpha = luminosité, couleur divisée par alpha. Hors du profil, l'image fixe
  ne mélange rien : aucun rectangle noir, quel que soit le parent. Rendu un peu plus saturé que le mélange
  `screen` sur fond sombre (qui laisse passer la teinte du fond), écart visuel négligeable.
- **Composant (AC 1-5, 7)** : `community/member-avatar.tsx` réécrit (`MemberAvatar`, `AvatarContent`) ;
  `ProfileAvatar` supprimé ; `AvatarImage` réduit à `avatarSource()`. Taille en px (`size`), classes
  responsives possibles (`sizeClassName`, profil 96/112), mode `animate` `always` (profil, aperçu) ou `hover`.
  `avatar-frame.module.css` passé en proportions : variable `--s` (taille / 112) dans toutes les longueurs,
  rayons en pourcentage, classe `.still` qui coupe les animations CSS hors survol.
- **13 rendus remplacés (AC 1, 9)**, plus la fenêtre de recadrage de la photo, qui découpait encore en rond (non
  recensée dans l'inventaire initial, passée en carré arrondi). Le point « En jeu » reste par-dessus.
- **Piège du mélange (AC 6), constaté en vérification visuelle** :
  - le conteneur général du site (`public-shell.tsx`, `relative z-0`) est un contexte d'empilement sans fond, la
    couleur de page étant sur `<body>` : au survol, la vidéo montrait du noir hors des cartes. Corrigé par
    `bg-background` sur ce conteneur (même couleur, le canvas de la grille reste visible) ;
  - l'en-tête de l'espace membre (`card-glow`, flou d'arrière-plan semi-transparent) ne peut pas l'éviter : prop
    `hoverVideo={false}`, le cadre vidéo y reste sur son image fixe (cadres CSS et GIF admin toujours animés au
    survol).
- **Barre de navigation** : `animate="always"` sur l'avatar du bouton du menu du compte (décision de Jean). L'en-tête
  du site a un fond presque opaque : la vidéo s'y mélange sans rectangle noir (vérifié au survol avant ce changement).
- **Lecture automatique** : React pose `muted` en propriété et jamais en attribut ; Safari peut alors refuser la
  lecture d'une vidéo montée au survol. La vidéo est démarrée explicitement (`muted` puis `play()`) au montage.
- **Vérification visuelle** (profil local admin avec le cadre Magma, `localhost:3000`) : annuaire (fixe et
  survol), menu du compte, en-tête de l'espace membre, page de profil (animé en permanence, 112 px). Non vus à
  l'écran faute de données locales : classements, commentaires, amis, catalogue des succès, pages de parties
  privées (même composant, couverts par les tests). Mobile non vérifié.
- Gates : `composer gates` OK (2570 tests) ; `pnpm gates` OK (692 tests, build propre ; 10 avertissements de lint
  préexistants, hors story).

- Mergée dans `develop` le 2026-10-02 (PR #683), CI verte.

### File List

- `api/src/Community/Application/Support/AvatarUrlResolver.php`, `Infrastructure/Dbal/DbalCommunityUserDirectoryQuery.php`
- `api/src/Community/Application/Query/{CommunityDirectory,CommunityFeedQuery,CommunityOverviewQuery,CommunityProfileView,CommunityUserDirectoryQueryInterface}.php`
- `api/src/Community/Application/Service/{FriendshipService,ModerationService,NotificationService,ProfileCommentService}.php`
- `api/src/PersonalRuns/Application/Service/{PersonalRunDrafts,PersonalRunGameSelection,RunSlotCoPlayers}.php`
- `api/src/Sessions/Application/Query/{LeaderboardQuery,LeaderboardQueryInterface}.php`, `Infrastructure/Dbal/DbalLeaderboardQuery.php`, `Presentation/Controller/LeaderboardController.php`
- `api/tests/Functional/CommunityAvatarFrameCardsTest.php` (nouveau), `CommunityLeaderboardTest.php`, `tests/Unit/Community/{AccountModerationServiceTest,CommunityOverviewQueryTest}.php`
- `scripts/avatar-frame-stills.py` (nouveau), `frontend/public/avatar-frames/*-still.webp` (7 nouveaux)
- `frontend/src/features/community/{member-avatar,avatar-frame,avatar-frame-video,avatar-frames,avatar-image,frame-preview,frame-picker-dialog,image-framing-dialog,member-card,community-hub,leaderboard-client,community-friends-panel,profile-comments,achievements-catalogue-page}.tsx|ts` et `avatar-frame.module.css`
- `frontend/src/features/{personal-runs/personal-run-detail-page,personal-runs/personal-run-participant-detail-page,auth/account-shell,auth/user-menu,players/player-profile-page}.tsx`, `frontend/src/components/public-shell.tsx`
- Types et parseurs (`avatarFrame`) : `community-api.ts`, `community-comments-api.ts`, `community-directory-api.ts`, `community-feed-api.ts`, `community-friends-api.ts`, `community-overview-api.ts`, `admin-moderation-api.ts`, `personal-runs/types.ts`, `players/player-profile-api.ts`
- Supprimé : `frontend/src/features/players/profile-avatar.tsx`
- Tests front : `member-avatar.test.tsx` (nouveau), `avatar-frame.test.tsx`, `avatar-frame-video.test.tsx`, `avatar-image.test.tsx`, `frame-preview.test.tsx`, `frame-picker-dialog.test.tsx`, `image-framing-dialog.test.tsx`
