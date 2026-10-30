# Epic 45: Mettre en avant nos partenaires

**Statut :** proposé le 2026-10-30
**Date :** 2026-10-30
**Origine :** échange avec Jean le 2026-10-30 - l'association va nouer des partenariats et veut les montrer sur le
site en général et pendant les événements.

## Constat

Rien n'existe aujourd'hui : aucune notion de partenaire dans l'API ni dans le front. Le product brief disait même
« No external sponsorship currently planned » (`product-brief-archilan.fr-distillate.md`), ce n'est plus vrai.

Cadrage de Jean :

- **5 partenaires au maximum**, sans niveaux (pas de « principal / soutien »), une liste à plat ordonnée.
- **Aucune contrepartie minimale promise**, on fait au mieux.
- Visibles sur le site (accueil compris) **et** pendant les événements (page de l'event, stream, écran sur place).

## Principes

- **Un soutien, pas une régie pub** : les partenaires apparaissent là où on parle de l'association et des
  événements, jamais au milieu des pages de parties, des profils ou de l'admin.
- **Résister aux bloqueurs de pub** : on l'a déjà payé avec `###adhesion` (EasyList, voir mémoire
  `adblock_url_words`). Aucun `sponsor`, `ad`, `ads`, `banner`, `partner-banner`, `promo` dans les routes, ids,
  classes ni noms de fichiers d'images. Route publique `/partenaires`. Chaque story vérifie l'affichage avec
  uBlock Origin (listes par défaut) activé.
- **Liens honnêtes** : les liens sortants vers un partenaire portent `rel="sponsored noopener noreferrer"`
  (consigne Google pour les liens issus d'un partenariat), ouverts dans un nouvel onglet.
- **Réutiliser** : le contexte `Content` (modèle `HomeAnnouncement` : entité admin + image dans le bucket public via
  `MinioStorageInterface` et `publicMedia`), l'accueil en sections (`features/home/home-sections.tsx`), le groupe de
  routes OBS `(overlay)` et ses paramètres (`features/overlay/overlay-params.ts`).
- **Rien d'affiché quand il n'y a rien** : sans partenaire actif, aucune section vide, aucun titre orphelin.

## Stories

### 45.1 - Gérer les partenaires en admin (socle)

En tant qu'admin, je veux ajouter, modifier, ordonner et désactiver les partenaires de l'association, afin que le
site affiche toujours la bonne liste sans passer par un développeur.

- Entité `Partner` dans `Content` : nom, logo (obligatoire pour être affiché), lien (URL https), description
  courte (une phrase, ~160 caractères), actif/inactif, position. Pas de niveau.
- Commande `ManagePartners` (create, edit, delete, reorder, attachLogo, removeLogo, sur le modèle de
  `ManageHomeAnnouncements`) ; upload du logo dans le bucket public (PNG, JPEG ou WebP, **pas de SVG** : risque XSS, un logo
  fourni en SVG est converti avant l'envoi). Un fond transparent est recommandé (le logo s'affiche sur fond clair
  et sombre).
- Requête `PartnerCatalogQuery` (`forVisitors()` : partenaires actifs avec logo, triés par position ;
  `forAdmin()` : tous). Détail : `implementation-artifacts/45-1-gestion-partenaires-admin.md`.
- Page admin `/admin/partenaires` (dans le menu admin à côté d'Annonces et de Bannières) : liste, formulaire,
  aperçu du logo sur fond clair **et** sombre, réordonnancement.
- Pas de plafond technique à 5 : la mise en page est conçue et testée de 1 à 6 partenaires ; au-delà de 5 actifs,
  l'admin voit un avertissement (« l'accueil est pensé pour 5 partenaires au plus »), sans blocage.

### 45.2 - Les partenaires sur le site : accueil, page dédiée, footer

En tant que visiteur, je veux voir qui soutient ArchiLAN, afin de connaître les partenaires de l'association et
d'aller vers eux.

- **Accueil** : nouvelle section `HomePartners` juste après `HomeAssociation`
  (`src/app/(public)/page.tsx`), titre du type « Ils soutiennent l'association ». Pour chaque partenaire : logo,
  nom, description courte, lien. Lien « Tous nos partenaires » vers `/partenaires`. Masquée sans partenaire actif.
- **Page `/partenaires`** : liste complète, une phrase sur ce que les partenaires apportent à l'association, et une
  invitation à devenir partenaire (lien de contact existant). Metadata SEO via `buildPageMetadata`, entrée dans le
  sitemap.
- **Footer** : simple lien texte « Partenaires » dans `public-shell.tsx`, à la suite des liens existants, affiché
  seulement s'il y a au moins un partenaire actif. Pas de bande de logos (doublon sur l'accueil, effet bandeau pub
  ailleurs).
- Fraîcheur : une modification admin apparaît sur l'accueil et `/partenaires` sans redéploiement. Vérifier comment
  l'accueil se revalide aujourd'hui après une modif admin (annonces d'accueil, ISR de l'epic 34) et faire pareil.

### 45.3 - Les partenaires d'un événement

En tant qu'admin, je veux associer des partenaires à un événement (avec, au besoin, ce qu'ils ont apporté), afin
de les remercier là où les joueurs regardent l'event.

- Dans l'édition d'un événement (`/admin/evenements`) : choisir des partenaires parmi les actifs, et pour chacun
  une mention libre optionnelle (« Lot offert par », « Salle prêtée par »...). Le lien est porté par `Events`
  (référence par identifiant de partenaire, pas d'import du domaine `Content`).
- Page publique de l'event (`/evenements/[eventSlug]`) : bloc « Avec le soutien de » (logo, nom, mention, lien).
- Récap d'une session d'événement (`/parties/[sessionId]`) : même bloc, discret, en fin de page. Les runs perso et
  hebdo ne sont pas concernées.
- Un partenaire désactivé ou supprimé disparaît des events, passés compris (on n'affiche pas un partenariat
  terminé).

### 45.4 - L'écran partenaires : stream et LAN

En tant qu'organisateur, je veux une page à afficher dans OBS et sur l'écran de la LAN, afin que les viewers et
les joueurs présents voient nos partenaires pendant l'event.

- Route publique dans le groupe `(overlay)` (sans shell, `noindex`), par exemple `/o/partenaires`, avec
  `?event=<slug>` pour n'afficher que les partenaires d'un événement (45.3) ; sans paramètre, tous les actifs.
- Deux modes :
  - **overlay** (défaut) : fond transparent, logos en rotation (un à la fois ou en bandeau), positionnable et
    redimensionnable via les paramètres existants (`pos`, `scale`, `duration`, `bg` pour un chroma key) ;
  - **écran** (`?mode=ecran`) : plein écran avec fond, titre « Merci à nos partenaires », logos grands et
    lisibles de loin, rotation lente.
- La page se met à jour seule (rechargement périodique de la liste, toutes les quelques minutes) : un partenaire
  ajouté en admin apparaît sans toucher à OBS.
- Dans l'admin de l'événement : le lien prêt à copier pour OBS et pour l'écran, avec un aperçu (damier pour la
  transparence, comme `OverlayLinksPanel`).

## Dépendances

- 45.1 avant toutes les autres.
- 45.2 et 45.3 sont indépendantes.
- 45.4 peut sortir avant 45.3 (tous les partenaires actifs) ; le filtre `?event=` arrive avec 45.3.

## Hors périmètre (pour plus tard, si un partenaire le demande)

- **Suivi des clics sortants** (`/go/<partenaire>` avec compteur) pour un bilan chiffré envoyé au partenaire.
- **Lots partenaires** liés aux succès, aux quêtes ou à la boutique (pelles, codes promo).
- **Niveaux de partenariat** et page individuelle par partenaire.
- **Gestion contractuelle** (conventions, montants, reçus fiscaux) : reste hors du site. À noter côté bureau :
  mécénat et parrainage n'ont pas le même traitement fiscal, ce qui peut changer la formulation affichée
  (« merci à » plutôt qu'une mise en avant commerciale).
