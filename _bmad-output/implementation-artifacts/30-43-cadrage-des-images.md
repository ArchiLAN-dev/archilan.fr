# Story 30.43: Cadrage de la photo de profil et de l'image de bannière

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-09-30
**Dépend de:** 30.42 (PR #663, composant `AvatarImage`)

## Story

En tant que membre qui a mis une photo de profil ou une image de bannière,
je veux choisir la partie de l'image qui est affichée (déplacer l'image et zoomer dans le cadre),
afin que mon visage ou le sujet de mon image ne soit pas coupé par un centrage automatique.

## Contexte

Demande de Jean (2026-09-30). Aujourd'hui la photo (ronde) et la bannière (bandeau) sont affichées en
`object-cover`, toujours centrées : l'utilisateur ne maîtrise pas ce qui est coupé.

Choix retenu (option 1) : **cadrage enregistré, image d'origine intacte**. On ne découpe pas le fichier : on
enregistre un point visé et un zoom, appliqués à l'affichage en CSS. Raisons :

- un GIF reste animé (le découper côté navigateur le figerait sur une image) ;
- la première image d'un GIF (30.40) a les mêmes dimensions que le GIF, le même cadrage s'y applique ;
- on recadre sans re-uploader, rien n'est perdu ;
- même logique que l'intensité de la bannière (30.41) : une valeur réglée dans la personnalisation, enregistrée
  avec le profil.

## Critères d'acceptation

1. **Modèle** : un cadrage = `x` et `y` (point visé, en % de l'image, 0 à 100) + `zoom` (en %, 100 à 300). Défaut
   `{x: 50, y: 50, zoom: 100}` (l'affichage actuel, centré). Un cadrage pour la photo, un pour la bannière.
2. **Enregistrement** : champs facultatifs `avatarFraming` et `bannerFraming` (`{x, y, zoom}`, entiers) dans la
   mise à jour du profil. Absent = inchangé ; hors bornes, non entier ou incomplet = 422 « Cadrage invalide. ».
3. **Remise à zéro** : un nouvel upload ou la suppression de l'image remet son cadrage au défaut (le cadrage
   d'une ancienne image n'a pas de sens sur la nouvelle).
4. **Exposition** : le profil public (`customization.avatarFraming` / `customization.bannerFraming`), l'édition du
   profil, et chaque carte qui porte une photo (`avatarFraming` à côté de `avatarUrl` / `avatarAnimatedUrl`,
   partout où 30.42 a ajouté `avatarAnimatedUrl`). Le cadrage ne s'applique qu'à une image **uploadée** : pour
   une photo Discord/Steam, sans image ou avec le cadrage par défaut, `avatarFraming` vaut `null` (affichage
   centré).
5. **Affichage** : partout où l'image s'affiche (page de profil, bannière, cartes et avatars `AvatarImage` de
   30.42, image fixe comme GIF au survol), l'image est positionnée sur le point visé et agrandie du zoom, sans
   jamais laisser de vide dans le cadre.
6. **Fenêtre de cadrage** (Radix Dialog) :
   - s'ouvre automatiquement après un upload réussi, et via un bouton « Recadrer » à côté de chaque image uploadée ;
   - montre l'image dans la forme finale (rond pour la photo, bandeau pour la bannière) ;
   - on déplace l'image au glisser (souris et tactile) et on zoome avec un curseur « Zoom » ; flèches du clavier
     pour déplacer (accessibilité) ; bouton « Recentrer » (défaut) ;
   - « Valider » applique le cadrage à l'aperçu du formulaire, « Annuler » le laisse tel quel ; l'enregistrement
     passe par la barre d'enregistrement, comme le reste du profil (30.41).
7. **Statut** (30.40) : quand un GIF est remplacé par sa première image (compte plus admin), le même cadrage
   s'applique.
8. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 2, 3) - API : objet de valeur `ImageFraming` (`final readonly`, bornes, défaut,
      `isValid`), six colonnes `smallint` (`avatar_framing_x/y/zoom`, `banner_framing_x/y/zoom`, défauts
      50/50/100) + migration, `CommunityProfile::reframeAvatar()` / `reframeBanner()` (pas de `set*`), remise au
      défaut dans `uploadCustom*` / `removeCustom*`, validation dans la mise à jour du profil ; tests unitaires et
      fonctionnels (défaut, enregistrement, absent = inchangé, refus, remise à zéro à l'upload).
- [x] **Task 2** (AC 4) - Exposition : profil public, édition, `AvatarUrlResolver::forCard` / `resolveForRow`
      renvoient aussi `avatarFraming` (requêtes brutes de l'annuaire et des classements : lire les colonnes),
      recopie partout où une carte est reconstruite (mêmes endroits que 30.42) ; formes PHPStan ; tests.
- [x] **Task 3** (AC 5, 7) - Front : fonctions pures `framingStyle(framing)` (`object-position` + `scale` avec
      `transform-origin` sur le point visé) ; appliquées dans `AvatarImage`, `ProfileAvatar`, `ProfileBanner` ;
      types `ImageFraming` ; tests.
- [x] **Task 4** (AC 6) - Front : `ImageFramingDialog` (fonctions pures `dragFraming` / `nudgeFraming` / bornes,
      testées sans DOM), branchée dans la personnalisation (ouverture après upload, bouton « Recadrer »), envoi
      par la barre d'enregistrement ; tests.
- [x] **Task 5** (AC 8) - Gates et vérification visuelle (photo ronde et bannière, GIF et image fixe).

## Notes techniques

- Pas de nouvelle librairie : glisser = événements pointer (`setPointerCapture`), déplacement converti en % de
  l'image selon la taille rendue et le zoom ; bornes pour ne jamais découvrir le fond.
- Le zoom agrandit autour du point visé (`transform-origin: x% y%`) dans un conteneur `overflow-hidden` (déjà le
  cas des avatars ronds et de la bannière depuis 30.40).
- Charge utile des cartes : `avatarFraming` vaut `null` quand il n'y a pas d'image uploadée ou que le cadrage
  est le défaut, pour ne pas alourdir les listes.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `ImageFramingTest` (défaut, lecture, 8 refus, bornes) | classe absente | `ImageFraming` |
| `CommunityImageFramingTest` (défaut + enregistrement, carte, absent = inchangé, refus, remise au centre) | champs absents | colonnes, `reframeAvatar` / `reframeBanner`, exposition |
| `image-framing.test.ts` (style, glisser, flèches, zoom, forme) | écrit avec le module | `framingStyle`, `dragFraming`, `nudgeFraming`, `zoomFraming` |
| `avatar-image.test.tsx` (+2 : point visé, zoom dans une boîte qui coupe) | pas de prop `framing` | `AvatarImage` |
| `image-framing-dialog.test.tsx` (rond, bandeau) | composant absent | `ImageFramingEditor` |

### Notes

- API : `ImageFraming` (`final readonly`, bornes ramenées à la lecture, `fromInput` pour le client), six colonnes
  `smallint` (migration `Version20260930180000`), `CommunityProfile::reframeAvatar()` / `reframeBanner()`, remise au
  centre dans `uploadCustom*` / `removeCustom*`. Mise à jour du profil : `avatarFraming` / `bannerFraming`, absent ou
  `null` = inchangé, sinon 422 « Cadrage invalide. ».
- Exposition : l'édition porte toujours les deux cadrages ; le profil public porte `avatarFraming` à côté de
  `avatarUrl` (la photo s'affiche même à qui ne voit pas la personnalisation) et `customization.bannerFraming` ;
  les cartes portent `avatarFraming` (null sans image uploadée ou centré). `AvatarUrlResolver::resolveForRow` prend
  désormais la ligne entière.
- Front : `framingStyle` (`object-position` + `scale` autour du point). `AvatarImage` enveloppe l'image d'un span
  `overflow-hidden` qui prend les classes quand il y a un zoom (les avatars de carte sont des `img` arrondies sans
  boîte). `ProfileAvatar` (le cadre coupe déjà) et `ProfileBanner` appliquent le cadrage.
- Fenêtre : `ImageFramingDialog` sur le `Dialog` partagé (39.11), glisser calculé depuis le début du geste (pas de
  perte à l'arrondi), flèches du clavier, curseur de zoom, « Recentrer ». Ouverte après un upload et par
  « Recadrer ». Le formulaire suit la remise au centre de l'API sans se marquer modifié.
- Écart : la fenêtre montre le cadre final seul, pas le reste de l'image grisé autour.
- Au passage (30.42) : le parseur de la page des succès perdait `avatarAnimatedUrl`, il le garde maintenant.

### Gates

- `composer gates` : OK (2540 tests, 15063 assertions).
- `pnpm gates` : typecheck, lint (0 erreur), 667 tests, build OK.

### Vérification visuelle

À faire sur le serveur de dev (migration `Version20260930180000` à appliquer).
