# Story 41.11: Bannières gérées par l'admin, animées comprises

**Status:** in-progress
**Epic:** 41 - Les Pelles, monnaie virtuelle d'ArchiLAN
**Date:** 2026-10-03

## Story

En tant qu'admin,
je veux ajouter une bannière de profil depuis l'administration, fixe ou animée, choisir à qui elle est ouverte et la
mettre en vente,
afin qu'un dessin de membre arrive dans la boutique sans développeur ni release, comme les cadres (41.10).

## Contexte

Une bannière est aujourd'hui un preset du code (`BannerPreset::ALL` côté API, `BANNER_PRESETS` côté front) : un
dégradé CSS animé, avec en dessous l'image personnelle éventuelle du membre (30.40) et un réglage d'opacité du preset
posé dessus (30.41). `BannerPreset::SHOP` est vide (41.7) : rien à vendre sans code. Elle ne s'affiche que dans
l'en-tête de la page profil et dans l'éditeur.

La story 41.10 a fait des cadres vidéo un catalogue géré par l'admin. Demande de Jean (2026-10-03) : la même chose
pour les bannières, avec la capacité de téléverser des bannières **animées**.

## Critères d'acceptation

### Catalogue

1. Table `profile_banner` : clé (slug unique, minuscules, chiffres et `_`, 2 à 32 caractères), nom, accès (`free`,
   `members`, `admins`, `shop`, mêmes règles que 41.10), clés des fichiers (image fixe obligatoire, vidéos `webm` et
   `mp4` facultatives mais ensemble), ordre, date de création, date de retrait. Une clé ne reprend pas celle d'un
   preset du code.
2. **Presets du code** : ils restent dans le code. Leur accès, leur nom et leur ordre se règlent depuis l'admin ; une
   ligne de surcharge sans fichiers n'est créée qu'au premier changement (comme les Légendaires en 41.10). Sans
   surcharge, un preset est `free` (sauf s'il est dans `BannerPreset::SHOP`).
3. **Accès** : la validation du profil (`UpdateCommunityProfile`) applique l'accès de la bannière choisie
   (« Réservée aux adhérents », « Réservée aux admins », « Bannière à acheter en boutique »). Une bannière retirée
   n'est plus montrée : le profil retombe sur le preset `default`, la clé enregistrée est gardée et la bannière
   revient si elle est rétablie. Comme en 41.10, adhérents et boutique ne sont vérifiés qu'au choix.

### Fichiers

4. **Bannière fixe** : une image WebP ou JPEG, 1200 x 300 au moins, 2400 x 800 au plus, rapport largeur / hauteur
   entre 3 et 6, 2 Mo au plus.
5. **Bannière animée** : la même image fixe (elle sert d'aperçu, d'image au chargement et de repli sous
   `prefers-reduced-motion`) plus une vidéo bouclée en WebM (VP9) et en MP4 (H.264), 6 Mo au plus chacune, signature
   vérifiée. L'API ne convertit rien : un fichier hors format est refusé avec sa raison.
6. Stockage dans le bucket média public (comme 41.10), clés `profile-banners/{clé}-{rôle}-{horodatage}.{ext}`.

### Admin

7. Page admin « Bannières » (`/admin/bannieres`) : liste avec aperçu (image fixe), accès, origine (code ou ajoutée)
   et état ; ajout d'une bannière (clé, nom, accès, image, vidéos facultatives) ; changement d'accès, de nom et
   d'ordre ; retrait et rétablissement. Pas de suppression.
8. Une bannière `shop` se met en vente dans `/admin/boutique` : `ShopCatalog` liste les bannières `shop` du
   catalogue en plus de `BannerPreset::SHOP`.

### Affichage

9. `GET /api/v1/profile-banners` (public, `max-age=300`) : les bannières non retirées (clé, nom, accès, code ou
   ajoutée, URL des fichiers ou `null` pour un preset du code).
10. `ProfileBanner` : une clé inconnue du code est résolue par le catalogue côté client (TanStack Query). Une bannière
    du catalogue prend la place du dégradé : image fixe, ou vidéo `autoplay muted loop playsinline` avec l'image en
    `poster`, image seule sous `prefers-reduced-motion`. L'image personnelle du membre et le réglage d'opacité gardent
    leur comportement : la bannière du catalogue est la couche « preset ». Côté serveur et avant le chargement, le
    preset `default` s'affiche.
11. Éditeur du profil : les bannières du catalogue rejoignent la grille, chacune verrouillée selon son accès, avec la
    raison au survol.

### Qualité

12. Gates : `composer gates` et `pnpm gates` verts. Pas de visuel généré par IA dans le dépôt ni dans les tests.

## Tasks / Subtasks

- [ ] **Task 1** (AC 1-3) - Entité, migration, catalogue et règles d'accès, validation du profil et affichage.
- [ ] **Task 2** (AC 4-8) - Validation des fichiers, commandes admin, endpoints, boutique ; tests fonctionnels.
- [ ] **Task 3** (AC 9-11) - Catalogue public, rendu `ProfileBanner`, grille de l'éditeur, page admin ; tests.
- [ ] **Task 4** (AC 12) - Gates.

## Notes techniques

- Calquer 41.10 : `AvatarFrameAccess` est réutilisé tel quel (renommé si besoin en un accès cosmétique commun),
  `ProfileBannerDefinition` / `ProfileBannerCatalog` / `ProfileBannerFileRule` / `ManageProfileBanners` /
  `ProfileBannerCatalogQuery` / `ProfileBannerController` dans Community.
- Vidéo plutôt que GIF : à durée égale, un WebM pèse une fraction d'un GIF pleine largeur, et la recette (ffmpeg) est
  déjà celle des cadres. Le GIF reste réservé à l'image personnelle des admins (30.40).
- Le recadrage (30.43) ne concerne que l'image personnelle ; la bannière du catalogue est en `object-cover` centré.
