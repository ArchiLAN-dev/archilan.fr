# Story 30.46: Cadres d'avatar vidéo (Feu et six effets)

**Status:** done
**Epic:** 30 - Communauté
**Date:** 2026-10-02

## Story

En tant que membre qui personnalise son profil,
je veux pouvoir choisir un cadre « Feu » où les bords de ma photo brûlent et où les flammes montent vers le ciel,
afin d'avoir un cadre spectaculaire et réaliste, au-delà des effets CSS actuels.

## Contexte

Story 30.23 a livré les cadres d'avatar en CSS/SVG. Les essais d'effets organiques (flammes, particules, SVG
`feTurbulence`, Lottie) ont tous été retirés : trop « dessinés », pas assez réalistes. Jean veut du réalisme : la
photo brûle, les flammes la contournent et montent.

La solution retenue est une **vidéo de vrai feu sur fond noir**, posée par-dessus l'avatar en
`mix-blend-mode: screen` : le noir disparaît, seule la lumière des flammes s'ajoute. Technique standard des effets
de feu ; pas de couche alpha, donc compatible avec tous les navigateurs, Safari compris.

### Assets (déjà produits)

Image de départ générée par GPT, animée par Kling (image de début = image de fin, boucle propre), puis retraitée
avec ffmpeg. Fichiers dans `C:/Users/maste/Downloads/cadre-feu/` :

| Fichier | Rôle | Taille |
|---|---|---|
| `fire.webm` | VP9, 512x512, 24 fps, 3 s, boucle | ~225 Ko |
| `fire.mp4` | H.264, même contenu, repli Safari | ~245 Ko |
| `fire-poster.webp` | première image, version fixe | ~26 Ko |

Vérifications faites sur ces fichiers : caméra fixe (ouverture identique au pixel sur les 73 images), raccord de
boucle invisible (écart dernière/première image égal à l'écart entre deux images consécutives), intérieur de
l'ouverture en noir pur (0-1/255), pas de piste audio, fondu sur les 90 px du haut pour que les flammes ne soient
pas coupées net.

### Géométrie de la vidéo (mesurée sur le 512 px)

- **Ouverture** (où se place la photo) : carré à coins arrondis, x 107 à 403, y 112 à 407, soit **296 px = 57,8 %**
  de la largeur de la vidéo.
- **Centre de l'ouverture** : (255 ; 259,5), soit **(49,8 % ; 50,7 %)**.
- Donc, pour un avatar de taille `A` : vidéo de largeur `A / 0,578 ≈ 1,73 A`, centrée sur l'avatar avec
  `translate(-49,8 %, -50,7 %)`.
- Débord visible des flammes autour de l'avatar : environ **38 % de A au-dessus**, **21 % de A** sur les côtés et
  en dessous.

## Critères d'acceptation

1. **Domaine** : `fire` est une clé valide de `AvatarFrame` ; elle se sauvegarde et se relit comme les autres
   (pas de migration : colonne `VARCHAR(32)` existante).
2. **Catalogue** : un cadre « Feu » (clé `fire`) apparaît dans la catégorie « Effets » du sélecteur de la
   personnalisation.
3. **Rendu animé** (page de profil et aperçu de la personnalisation) : la vidéo en boucle, muette, sans contrôles,
   est posée par-dessus l'avatar en `mix-blend-mode: screen`, ouverture alignée sur la photo (géométrie ci-dessus),
   flammes débordant autour. La photo reste entièrement visible et nette au centre.
4. **Pas de boîte noire** : nulle part le fond noir de la vidéo n'apparaît comme un rectangle sombre, ni sur la
   bannière, ni sur la carte, ni sur le fond de page. (Voir « Piège : le mélange ne traverse pas un contexte
   d'empilement ».)
5. **Mouvement réduit** : sous `prefers-reduced-motion: reduce`, aucune vidéo n'est chargée ni jouée ; c'est
   `fire-poster.webp` qui s'affiche, avec le même mélange et la même géométrie.
6. **Rendu serveur** : le HTML serveur contient l'image fixe (pas de `<video>`) ; la vidéo ne remplace l'image
   qu'après montage côté client, si le mouvement est permis. Pas d'erreur d'hydratation.
7. **Sélecteur** : la vignette « Feu » (44 px) montre l'image fixe **contenue dans la vignette**, sans déborder sur
   les vignettes voisines, et sans vidéo.
8. **Accessibilité** : la vidéo et l'image fixe sont décoratives (`aria-hidden`, `alt=""`), sans focus, sans
   `pointer-events`. Le nom à côté de l'avatar reste lisible.
9. **Autres cadres inchangés** : aucun changement visuel pour les 14 cadres existants ni pour « Aucun ».
10. `composer gates` et `pnpm gates` passent.
11. **Extension (Jean, 2026-10-02)** : six autres cadres vidéo rejoignent « Effets », sur la même variante et la
    même géométrie : `electric` (Électrique), `spectral_fire` (Flammes spectrales, **nouveau** cadre à côté du
    `spectral` CSS, qui reste), `lava` (Magma), `runes` (Runes arcaniques), `cosmic` (Portail cosmique), `glitch`
    (Glitch). Le feu passe en version stylisée (v2).
12. **Catégorie dédiée (Jean, 2026-10-02)** : les sept cadres vidéo forment leur propre catégorie
    « Légendaires », affichée après « Effets » dans le sélecteur. « Effets » garde les trois effets CSS
    (holographique, or scintillant, spectre).
13. **Essayer avant d'enregistrer (Jean, 2026-10-02)** : cliquer un cadre le montre sur la vraie photo du membre,
    à taille profil, avec la place pour les effets qui débordent ; rien n'est enregistré avant « Enregistrer ».
    L'aperçu reste visible en faisant défiler les vignettes (desktop). Les sections « Photo de profil » et
    « Cadre d'avatar » n'en font plus qu'une, « Photo de profil et cadre » : l'aperçu remplace l'ancien avatar
    de la section photo, les boutons d'import et le sélecteur de cadre sont à côté. Pas de texte d'état ni de
    bouton « revenir » sous l'aperçu (retirés à la demande de Jean : ils décalaient la mise en page).
14. Passer d'un cadre vidéo à un autre change bien l'effet affiché (bug trouvé par Jean à l'essai).
15. **Légendaires réservés aux admins (Jean, 2026-10-02), pour commencer** : l'API refuse un cadre légendaire à un
    non-admin (422 `avatarFrame` « Cadre réservé aux admins. ») et l'éditeur reçoit `legendaryFramesAllowed`. Un
    admin rétrogradé ne montre plus son cadre légendaire (la clé reste stockée et revient s'il redevient admin),
    comme le GIF d'un admin (30.40). Dans le sélecteur, l'onglet et les vignettes portent un cadenas et ne se
    choisissent pas.
16. **Sélecteur en fenêtre dédiée (Jean, 2026-10-02, inspiré du sélecteur de décorations de Discord et des
    casiers de jeux)** : la carte « Photo de profil et cadre » montre l'aperçu sur la bannière, le cadre actuel et
    un bouton « Changer le cadre ». Il ouvre une fenêtre : à gauche, toutes les cartes de même taille et alignées,
    regroupées sous de simples titres (Couleurs, Néon, Effets, Légendaires ; « Aucun » en tête), chacune sur la
    photo du membre ; à droite, le grand aperçu sur sa bannière. Les Légendaires se distinguent par un contour
    doré de rareté (pas par une taille) et jouent leur vidéo au survol ou au focus (jamais sous « réduire les
    animations »). Un point discret marque le cadre enregistré. « Appliquer » met le cadre dans le brouillon,
    « Annuler » ferme sans rien changer ; seul « Enregistrer » le valide. La barre d'onglets et les vignettes de
    tailles différentes d'une version intermédiaire ont été abandonnées (alignement, rendu).

## Tasks / Subtasks

- [x] **Task 1** (AC 1) - API : ajouter `fire` à `AvatarFrame::ALL` ; étendre
      `CommunityProfileCustomizationTest::testAvatarFrameRoundTripAndValidation` (aller-retour de `fire`).
- [x] **Task 2** (AC 2) - Assets : copier les trois fichiers dans `frontend/public/avatar-frames/`
      (`fire.webm`, `fire.mp4`, `fire-poster.webp`) ; ajouter le cadre au catalogue `avatar-frames.ts`
      (nouvelle variante `video`, avec les chemins des assets).
- [x] **Task 3** (AC 3, 5, 6, 8) - `avatar-frame.tsx` : rendu de la variante `video` (calque superposé, image fixe au
      rendu serveur, vidéo après montage si mouvement permis) ; styles dans `avatar-frame.module.css`.
- [x] **Task 4** (AC 4) - Vérifier et corriger les contextes d'empilement entre le calque et ce qu'il recouvre, sur
      la page de profil (bannière) et dans l'aperçu de la personnalisation.
- [x] **Task 5** (AC 7) - Vignette du sélecteur : image fixe contenue.
- [x] **Task 6** (AC 3-9) - Tests jest (`avatar-frame.test.tsx`) + vérification visuelle dans le navigateur (profil
      clair et sombre si applicable, bannière image et preset, mobile 96 px et desktop 112 px, mouvement réduit).
- [x] **Task 7** (AC 10) - Gates.
- [x] **Task 8** (AC 11) - Six clés dans `AvatarFrame::ALL` + `AvatarFrameTest` ; assets dans
      `public/avatar-frames/` ; catalogue via un helper `videoFrame()` ; tests jest (catalogue + présence des trois
      fichiers de chaque cadre vidéo).

## Dev Notes

### Ce qui existe (à réutiliser, pas à réinventer)

- `api/src/Community/Domain/ValueObject/AvatarFrame.php` : liste blanche `ALL` + `isValid()`. Le backend ne fait que
  valider la clé ; aucun autre fichier API à toucher (`UpdateCommunityProfile` et `CommunityProfileView` passent la
  clé telle quelle).
- `frontend/src/features/community/avatar-frames.ts` : catalogue sérialisable (`key`, `label`, `category`,
  `variant`, `color?`). Type `AvatarFrameVariant` à étendre avec `"video"`.
- `frontend/src/features/community/avatar-frame.tsx` : `<AvatarFrame frameKey className>` enveloppe le contenu.
  Structure actuelle : `.frame` (anneau, `padding: 3px`, `isolation: isolate`) > `.inner` (photo,
  `overflow: hidden`). La variante `spectral` ajoute déjà un calque décoratif (`particleLayer`) : même idée.
- `frontend/src/features/players/profile-avatar.tsx` : **seul** utilisateur de `AvatarFrame` avec une photo
  (`size-24 sm:size-28`, soit 96/112 px). Utilisé par la page de profil et par l'aperçu de la personnalisation. Les
  listes (annuaire, commentaires, classements) n'affichent **pas** de cadre : aucune surface « petite » à gérer en
  dehors de la vignette du sélecteur.
- `community-profile-customization-form.tsx` : `FrameSwatch` rend `<AvatarFrame className="size-11">` dans un
  bouton `w-16` ; les catégories viennent de `FRAME_CATEGORIES`. Un cadre retiré du catalogue est remis à « Aucun »
  au chargement (ligne ~184) : rien à faire.
- Mouvement réduit côté JS : `avatar-image.tsx` (story 30.42) lit
  `window.matchMedia("(prefers-reduced-motion: reduce)")`. `profile-banner.tsx` utilise
  `<source media="(prefers-reduced-motion: reduce)">` dans un `<picture>`. Le `use-reduced-motion.ts` de 30.23 a
  été supprimé : en écrire un petit si besoin, ou lire `matchMedia` dans un `useEffect` comme 30.42.

### Rendu attendu de la variante `video`

Pour `fire`, l'avatar n'a **pas** d'anneau CSS : le bord brûlé de la vidéo fait office de cadre.

```
<div class="videoFrame size-24 sm:size-28">      position: relative ; pas d'isolation
  <div class="inner">photo</div>                  même rendu que les autres cadres (border-radius, overflow)
  <img|video class="videoLayer" aria-hidden />    position: absolute ; width: 173% ; left/top: 50% ;
                                                  transform: translate(-49.8%, -50.7%) ;
                                                  mix-blend-mode: screen ; pointer-events: none
</div>
```

- Rendu serveur et premier rendu client : `<img src="/avatar-frames/fire-poster.webp" alt="">`.
- Après montage, si le mouvement est permis : `<video autoPlay muted loop playsInline disablePictureInPicture
  preload="auto" poster=...>` avec `<source type="video/webm">` puis `<source type="video/mp4">`. `muted` est
  obligatoire pour l'autoplay. Écouter le changement de préférence (`change` sur le `MediaQueryList`) est un plus,
  pas une obligation.
- Le calque doit passer **au-dessus** de la photo (dans le même parent, après `.inner`).

### Piège : le mélange ne traverse pas un contexte d'empilement

`mix-blend-mode` ne mélange qu'avec le contenu du **contexte d'empilement** qui contient l'élément. Si un ancêtre du
calque crée un contexte d'empilement (z-index sur un élément positionné, `isolation: isolate`, `opacity < 1`,
`transform`, `filter`...), tout ce qui est en dehors (la bannière par exemple) n'est pas vu : le noir de la vidéo
se mélange avec du transparent et **s'affiche en noir**. C'est l'AC 4.

Cas connus à traiter :

- `.frame` a `isolation: isolate` : la variante `video` ne doit pas l'utiliser (classe dédiée).
- `player-profile-page.tsx` : le bloc identité est dans `<div className="relative z-10 ...">` (ligne ~47), qui crée
  un contexte d'empilement. Les flammes au-dessus de l'avatar débordent sur la bannière, donc elles afficheraient
  un rectangle noir sur la bannière. `ProfileBanner` est `relative` **sans** z-index : un frère positionné placé
  après dans le DOM se peint déjà au-dessus. Retirer `z-10` (garder `relative`) devrait suffire ; **le vérifier
  visuellement** avec une bannière image et une bannière preset.
- Aperçu de la personnalisation : vérifier qu'aucun ancêtre de `ProfileAvatar` (`Section`, carte du formulaire)
  ne crée de contexte d'empilement ; sinon, le calque mélange avec le fond de la carte, ce qui reste correct si ce
  fond est opaque et sombre. Le critère est visuel : pas de rectangle noir.

Plan B si un contexte d'empilement ne peut pas être évité : produire une version **avec couche alpha** à partir de
la même source (alpha = luminosité du feu). Plus lourd (VP9 alpha pour Chrome/Firefox, mais Safari ne lit pas le
VP9 alpha : il faudrait du HEVC alpha ou un WebP animé). À ne faire que si le plan A échoue ; demander à Jean
avant.

### Débord et rognage

- Débord des flammes : environ 38 % de la taille de l'avatar au-dessus, 21 % sur les côtés. Sur mobile (96 px) :
  environ 20 px de chaque côté, et le bloc a `px-5` (20 px) dans un `<header>` en `overflow-hidden` : les flammes de
  gauche arrivent juste au bord de la carte. Vérifier qu'aucune coupure nette n'est visible ; sinon réduire
  légèrement la vidéo ou ajouter un fondu latéral dans l'asset (pas en CSS `mask`, pour rester simple).
- À droite, les flammes passent sous le début du nom (`gap-4`, 16 px). Le titre a déjà un `text-shadow` ; vérifier
  qu'il reste lisible (AC 8).
- Le calque ne doit pas changer la taille de mise en page de l'avatar (position absolue).

### Vignette du sélecteur (AC 7)

`FrameSwatch` aligne les vignettes dans des boutons de 64 px : un calque à 173 % (76 px) déborderait sur les
voisines. Pour la vignette, afficher `fire-poster.webp` à l'intérieur de la case (clip par la case, image fixe,
pas de vidéo). Moyen suggéré : prop optionnelle sur `AvatarFrame` (par exemple `preview`) passée par
`FrameSwatch`, que la variante `video` interprète ; les autres variantes l'ignorent.

### Tests

- API : aller-retour de `fire` dans `CommunityProfileCustomizationTest` (test fonctionnel existant ; suivre son
  style).
- Front, `avatar-frame.test.tsx` (nouveau, style `renderToStaticMarkup` comme `avatar-image.test.tsx`) :
  - `fire` au rendu serveur : contient l'image fixe `fire-poster.webp`, aucun `<video>` ;
  - un cadre CSS existant (`gold`) : aucun calque image/vidéo ;
  - mode vignette : image fixe, pas de vidéo ;
  - le catalogue contient `fire` dans « Effets » avec la variante `video`.
- Visuel (navigateur, obligatoire pour AC 3, 4, 7, 8) : voir Task 6. Rappel worktree : vérification visuelle sur
  `127.0.0.1`.

### Reproduire les assets

Si l'asset doit être refait (autre source Kling, recadrage), la chaîne ffmpeg utilisée part du MP4 Kling 960x960
dont l'ouverture faisait 603 x 555 px (image GPT d'origine non carrée) :

```
F="scale=960:1044,pad=1044:1044:42:0:black,scale=512:512:flags=lanczos,
   lutrgb=r='if(lt(val,12),0,val)':g='if(lt(val,12),0,val)':b='if(lt(val,12),0,val)',
   format=gbrp,geq=r='r(X,Y)*min(1,Y/90)':g='g(X,Y)*min(1,Y/90)':b='b(X,Y)*min(1,Y/90)'"
# webm : VP9 2 passes, -b:v 600k, -an ; mp4 : libx264 -crf 26 -preset slow -movflags +faststart, -an
# poster : -frames:v 1 en .webp
```

Étapes : étirement vertical pour rendre l'ouverture carrée, mise au carré, 512 px, noirs proches de 0 forcés à 0,
fondu sur les 90 px du haut. Une autre source aura une autre géométrie : remesurer l'ouverture et mettre à jour les
pourcentages.

### Garde-fous

- Ne pas réintroduire `lottie-react` ni de fichier `fire-svg` (retirés en 30.23).
- Composants purs : pas d'effet de bord au rendu ; la lecture de `matchMedia` se fait dans un effet.
- Pas d'em-dash ni d'en-dash dans le code, les commentaires, les commits.
- Branche : `feature/epic-30-story-46-cadre-de-feu-video` depuis `develop`.

### References

- [Source: _bmad-output/implementation-artifacts/30-23-avatar-frames.md] cadres d'avatar, essais retirés
- [Source: _bmad-output/implementation-artifacts/30-42-avatar-anime-au-survol.md] mouvement réduit côté JS
- [Source: _bmad-output/implementation-artifacts/30-24-profile-header-layout.md] en-tête de profil
- [Source: api/src/Community/Domain/ValueObject/AvatarFrame.php]
- [Source: frontend/src/features/community/avatar-frame.tsx, avatar-frame.module.css, avatar-frames.ts]
- [Source: frontend/src/features/players/profile-avatar.tsx, player-profile-page.tsx]
- [Source: frontend/src/features/community/community-profile-customization-form.tsx] `FrameSwatch`

## Dev Agent Record

### Agent Model Used

Claude Opus 5.5 (1M context)

### Debug Log References

### Completion Notes List

- `fire` ajouté à `AvatarFrame::ALL` ; l'aller-retour est couvert dans `testAvatarFrameRoundTripAndValidation`.
- Variante `video` : `.videoFrame` (sans anneau, sans isolation) > `.videoInner` (photo) + calque
  `AvatarFrameVideoLayer`. Le calque lit la préférence de mouvement avec `useSyncExternalStore` (instantané serveur
  = pas de mouvement) : le serveur et le premier rendu client envoient l'image fixe, la vidéo prend le relais après
  hydratation, sans écart d'hydratation, et suit un changement de préférence en direct.
- Tailwind preflight impose `max-width: 100%` aux médias : `.videoLayer` le lève (`max-width: none`), sinon la vidéo
  serait bornée à la taille de l'avatar.
- Vignette : prop `preview` sur `AvatarFrame`, passée par `FrameSwatch` ; l'image fixe remplit la case et le contenu
  (étoile / coche) se place dans l'ouverture. Les autres variantes ignorent la prop.
- `player-profile-page.tsx` : `z-10` retiré du bloc identité (il créait un contexte d'empilement). Le bloc reste
  au-dessus de la bannière par l'ordre du DOM.
- Vérification visuelle (profil public local, desktop, avatar 112 px, bannière image claire) : vidéo jouée en WebM,
  aucun rectangle noir sur la bannière, photo nette, nom lisible, flammes de gauche juste dans la carte. Le
  navigateur n'était pas connecté au site local et l'écriture en base locale n'a pas été faite : la structure du
  cadre a été recréée dans la page avec les vraies classes du module CSS, plutôt que rendue par le composant
  lui-même. Non vérifiés à l'écran : l'aperçu de la personnalisation (connexion requise), la largeur mobile
  (96 px), le mouvement réduit (couvert par les tests unitaires du rendu serveur).
- Limite connue : sur une bannière très claire, le mode `screen` atténue les flammes (la lumière s'ajoute à du
  clair). Sur les fonds sombres du site, le rendu est plein.

- Feu v2 (stylisé jeu vidéo, choisi par Jean le 2026-10-02) : remplace le feu réaliste. Même clé `fire`, mêmes
  chemins, même géométrie (ouverture 107..403 x 111..407 sur 512 px, à 3 px près) : aucun code ne change. Le
  montage ajoute un masque intérieur progressif (les traînées qui entraient dans l'ouverture disparaissent, les
  petites flammes du bord restent) et un fondu sur les bords de la source. webm 227 Ko, mp4 326 Ko, poster 21 Ko.
- Travail déplacé dans le worktree `../archilan-cadres` : une autre session avait basculé le tree principal sur la
  story 38.14 pendant le développement. Gates relancées sur la branche seule.

- Six cadres de plus (AC 11), produits avec la même recette (image GPT corrigée, Kling début = fin, montage
  ffmpeg). Tous calés sur la géométrie du feu (ouverture 107..403 x 111..407, à 3 px près), donc aucun CSS
  propre à un cadre. Magma : fondu enchaîné de 0,5 s sur la boucle (la bande et les gouttes sautaient au
  raccord), boucle de 2,5 s. Glitch : le contour tremble volontairement, aligné sur sa position médiane.
  Poids : 190 à 230 Ko en webm, 70 à 350 Ko en mp4, moins de 25 Ko par poster.
- Garde-fou ajouté : un test jest échoue si un cadre vidéo du catalogue n'a pas ses trois fichiers dans
  `public/` (un cadre ajouté sans ses assets afficherait une image cassée).

- Catégorie « Légendaires » (AC 12) : `AvatarFrameCategory` étendu, `FRAME_CATEGORIES` du sélecteur aussi ; le
  sélecteur rend les catégories de façon générique, rien d'autre à toucher.

- Aperçu d'essai (AC 13) : `frame-preview.tsx` (`FramePreview`), testé dans `frame-preview.test.tsx`. Le cadre
  cliqué restait déjà un brouillon ; le seul aperçu était dans « Photo de profil », hors de vue pendant le choix,
  d'où la fusion des deux sections. Encadré de 48 px sur les côtés, 56 px en haut et 40 px en bas : assez pour
  les débords mesurés (38 % de l'avatar au-dessus, 21 % sur les côtés).
- Bug corrigé (AC 14) : un navigateur ne recharge pas un `<video>` dont seuls les `<source>` changent, donc en
  passant du Feu à l'Électrique l'aperçu continuait de jouer le feu. Le `<video>` porte maintenant une clé liée
  à son fichier : React le remplace. L'élément est construit par une fonction pure `videoLayer()`, testée
  (`avatar-frame-video.test.tsx`, qui échoue sans la clé). Un test en jsdom n'était pas possible : le setup
  jest global démarre MSW, qui exige `fetch`.

- Gating (AC 15) : `AvatarFrame::LEGENDARY`, `allowedFor()`, `displayed()` (règles pures du domaine) ;
  `UpdateCommunityProfile::update()` reçoit `$isAdmin` du contrôleur (rôle ROLE_ADMIN, stable, comme les autres
  droits admin du contexte) ; `CommunityProfileView` applique `displayed()` au profil public et à l'éditeur.
  Tests : `AvatarFrameTest` (règles), `CommunityProfileCustomizationTest` (non-admin refusé, admin accepté, admin
  rétrogradé sans cadre).
- Sélecteur (AC 16) : `frame-picker-dialog.tsx` (`FramePickerDialog`, contenu `FramePicker` testé dans
  `frame-picker-dialog.test.tsx`). Le dialogue partagé gagne une taille `wide` (max-w-4xl) ; les écrans admin
  gardent la taille par défaut. `AvatarContent` extrait de `ProfileAvatar` pour les cartes ;
  `AvatarFrameSwatchLayer` ne joue la vidéo que pendant le survol ; un cadenas plutôt qu'un filtre CSS (opacité
  ou grayscale créent un contexte d'empilement et le noir de l'effet apparaîtrait). `FramePreview` pose la
  bannière du membre derrière l'avatar, sans z-index (testé).
- Gates (branche seule, base de test isolée) : `composer gates` OK (2569 tests, 15327 assertions) ; `pnpm gates` OK
  (683 tests, build propre ; les 10 avertissements de lint préexistent, hors des fichiers de la story).

- Mergée dans `develop` le 2026-10-02 (PR #675, commit de merge `ff8b1de4`), CI verte.

### File List

- `api/src/Community/Domain/ValueObject/AvatarFrame.php` (modifié)
- `api/tests/Functional/CommunityProfileCustomizationTest.php` (modifié)
- `frontend/public/avatar-frames/fire.webm` (nouveau)
- `frontend/public/avatar-frames/fire.mp4` (nouveau)
- `frontend/public/avatar-frames/fire-poster.webp` (nouveau)
- `frontend/public/avatar-frames/{electric,spectral-fire,lava,runes,cosmic,glitch}.{webm,mp4}` et `-poster.webp` (nouveaux, 18 fichiers)
- `api/tests/Unit/Community/AvatarFrameTest.php` (nouveau)
- `frontend/src/features/community/avatar-frames.ts` (modifié)
- `frontend/src/features/community/avatar-frame.tsx` (modifié)
- `frontend/src/features/community/avatar-frame-video.tsx` (nouveau)
- `frontend/src/features/community/avatar-frame.module.css` (modifié)
- `frontend/src/features/community/avatar-frame.test.tsx` (nouveau)
- `frontend/src/features/community/community-profile-customization-form.tsx` (modifié)
- `frontend/src/features/community/frame-preview.tsx` (nouveau)
- `frontend/src/features/community/frame-preview.test.tsx` (nouveau)
- `frontend/src/features/community/frame-picker-dialog.tsx` (nouveau)
- `frontend/src/features/community/frame-picker-dialog.test.tsx` (nouveau)
- `frontend/src/components/ui/dialog.tsx` (modifié : taille `wide`)
- `frontend/src/features/community/community-profile-api.ts` (modifié : `legendaryFramesAllowed`)
- `frontend/src/features/players/profile-avatar.tsx` (modifié : `AvatarContent` extrait)
- `api/src/Community/Application/Command/UpdateCommunityProfile.php` (modifié)
- `api/src/Community/Application/Query/CommunityProfileView.php` (modifié)
- `api/src/Community/Presentation/Controller/CommunityProfileController.php` (modifié)
- `frontend/src/features/community/avatar-frame-video.test.tsx` (nouveau)
- `frontend/src/features/players/player-profile-page.tsx` (modifié)
- `frontend/src/features/players/player-profile-page.test.tsx` (modifié : classe sans `z-10`, et garde-fou contre son retour)
