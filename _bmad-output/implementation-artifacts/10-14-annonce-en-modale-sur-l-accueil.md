# Story 10.14: Annonce en modale sur la page d'accueil

**Status:** review
**Epic:** 10 - Identité visuelle & médias - pages publiques
**Date:** 2026-10-12

## Story

En tant qu'admin d'ArchiLAN,
je veux programmer une annonce qui s'ouvre en modale sur la page d'accueil pendant une période donnée,
afin de pousser un temps fort (l'ouverture des inscriptions à l'ArchiLAN 4) auprès de chaque visiteur, sans
toucher au code.

En tant que visiteur,
je veux voir l'annonce une fois, cliquer sur son bouton ou la fermer d'un clic à côté,
afin d'être informé sans être gêné à chaque visite.

## Contexte

Demande de l'équipe (forum Discord, 2026-10-10) : une modale conditionnelle sur l'accueil, avec un texte et/ou une
image suivis d'un bouton d'appel à l'action (« Inscrivez-vous dès maintenant ») qui redirige vers une autre page. Cas
d'usage immédiat : les candidatures à l'ArchiLAN 4, envisagées du lundi 19 octobre 00h01 au vendredi 31 octobre 23h59 ;
le lendemain de la clôture, la modale ne s'affiche plus. À terme, plusieurs annonces se configurent depuis l'admin
des contenus.

Briques existantes :

- Le bandeau HelloAsso de la boutique (41.14, `ShopAnnouncement`) : message saisi par l'admin et date de fin. Même
  idée, mais un bandeau sur une seule page.
- L'upload d'une image de couverture d'article (10.7, `AdminPostCoverImageController`) : même chemin pour l'image de
  l'annonce, dans le bucket public (34.x).
- L'accueil est en ISR, régénéré toutes les 5 minutes (34.4) : la page servie peut dater de 5 minutes. La fenêtre de
  dates est donc vérifiée **côté navigateur** à l'heure réelle, pas seulement au rendu serveur.

## Critères d'acceptation

1. **Une annonce** porte : un titre (obligatoire), un texte (facultatif, court, Markdown léger comme les articles),
   une image (facultative), un libellé de bouton et un lien (obligatoires ensemble ; lien interne `/...` ou `https://`),
   une date de début et une date de fin (obligatoires, fin postérieure au début et à venir, heure de Paris). Le titre
   suffit : texte et image restent facultatifs. Migration réversible.
2. **Fenêtre** : l'annonce ne s'affiche qu'entre son début et sa fin, comparés à l'heure courante du navigateur au
   moment de l'affichage (l'ISR ne doit pas la montrer avant l'heure ni après la clôture). Hors fenêtre, rien ne
   s'affiche et aucune place n'est réservée dans la page.
3. **Une seule à la fois** : deux annonces ne peuvent pas avoir de fenêtres qui se chevauchent (refus à
   l'enregistrement, message clair). Si aucune n'est en cours, l'accueil est inchangé.
4. **La modale** s'ouvre sur la page d'accueil uniquement, peu après le chargement (sans bloquer le rendu ni le LCP) :
   image en tête si présente, titre, texte, bouton. Le bouton mène au lien (même onglet pour un lien interne, nouvel
   onglet pour un lien externe) et ferme la modale.
5. **Fermeture** : clic à côté, touche Échap ou bouton « Fermer » (croix nommée pour les lecteurs d'écran). Le focus
   est piégé dans la modale tant qu'elle est ouverte, et rendu à la page à la fermeture.
6. **Pas à chaque visite** : une fois fermée ou suivie, l'annonce ne se rouvre plus dans ce navigateur (mémorisé par
   identifiant d'annonce ; une nouvelle annonce se réaffiche). Si le stockage du navigateur est indisponible, la
   modale s'affiche au plus une fois par chargement de page, sans erreur.
7. **Admin** (`/admin/annonces`, entrée dans le menu admin à côté de « Contenu ») : liste des annonces avec leur
   statut (à venir / en cours / terminée), création, modification, suppression, upload et retrait de l'image, et un
   aperçu de la modale telle que le visiteur la verra.
8. **Lecture publique** : `GET /api/v1/home-announcement` (sans session) renvoie l'annonce en cours ou la prochaine à
   venir dans les 24 h (pour que la page ISR la connaisse avant son début), `null` sinon. Routes admin sous
   `/api/v1/admin/announcements`, réservées aux admins.
9. **Téléphone** : la modale tient à l'écran (image recadrée, texte qui défile si long, bouton toujours visible), sans
   défilement horizontal. Thèmes clair et sombre.
10. Tests (fenêtre de dates aux bornes, chevauchement refusé, lien invalide refusé, droits admin, lecture publique,
    modale : fermeture, mémorisation, hors fenêtre) et gates verts.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 3) - Entité `HomeAnnouncement` (contexte Content) : création, modification, validations
  (dates, lien, texte ou image, chevauchement) ; repository ; migration ; tests.
- [x] **Task 2** (AC 7, 8) - Commandes `ManageHomeAnnouncement` (create / edit / delete), upload d'image calqué sur
  la couverture d'article ; requête publique `HomeAnnouncementQuery` ; contrôleurs admin et public ; tests
  fonctionnels.
- [x] **Task 3** (AC 2, 4, 5, 6, 9) - Front : `HomeAnnouncementModal` (client) montée sur l'accueil, fenêtre vérifiée
  à l'heure du navigateur, mémorisation par id, accessibilité ; tests.
- [x] **Task 4** (AC 7) - Front admin `/admin/annonces` : liste, formulaire, image, aperçu ; tests.
- [x] **Task 5** (AC 10) - Gates ; vérification visuelle (accueil sur ordinateur et téléphone, admin par Jean).

## Notes techniques

- Contexte `Content`, à côté de `Post` : l'annonce est un contenu éditorial, pas une promo de boutique.
- Heures : saisie et affichage en Europe/Paris, stockage `datetimetz_immutable`. Le formulaire admin propose 00:01 et
  23:59 par défaut pour coller au besoin « du 19 au 31 inclus ».
- Le rendu serveur peut inclure l'annonce (ISR) ; la décision d'ouvrir la modale se prend dans un composant client
  avec l'heure courante. Clé de stockage navigateur : `home-announcement-seen:<id>`, lecture et écriture sous
  try/catch.
- Lien : interne (`/...`) ou `https://` seulement ; tout autre schéma est refusé côté API.
- Pas de suivi des clics dans cette story.

## Hors périmètre

- Ciblage (membres connectés seulement, pages autres que l'accueil).
- Plusieurs annonces simultanées ou en carrousel.
- Statistiques d'affichage et de clic.

## Dev Agent Record

- API (contexte Content) : entité `HomeAnnouncement` (validations, `statusAt`, `overlaps`, `isAcceptedLink`), migration
  `Version20261012100000`, `ManageHomeAnnouncements` (create / edit / delete / attachImage / removeImage, refus du
  chevauchement), `HomeAnnouncementQuery` (`forVisitors` : en cours ou à venir sous 24 h ; `forAdmin` avec statut),
  `HomeAnnouncementController` (`GET /api/v1/home-announcement`, `/api/v1/admin/announcements[/{id}[/image]]`).
- Image : clé `home-announcements/{id}/image-{stamp}.{ext}` dans le bucket public, une nouvelle clé par envoi (pas de
  copie en cache sous la nouvelle image).
- Front : `HomeAnnouncementModal` sur l'accueil (lue avec la page ISR, ouverte par le navigateur après 800 ms, ou à
  l'heure de début si la page est ouverte avant ; Radix Dialog : clic à côté, Échap, croix, focus piégé ;
  `localStorage` `home-announcement-seen:<id>` sous try/catch). Bouton : `Link` pour une page du site, nouvel onglet
  pour https. Admin `/admin/annonces` (menu Contenu) : liste avec statut, formulaire en panneau (date + heure, 00:01 et
  23:59 par défaut), image avec aperçu local, aperçu de la modale, suppression confirmée.
- Tests : `tests/Unit/Content/HomeAnnouncementTest.php`, `tests/Functional/HomeAnnouncementTest.php`,
  `home-announcement.test.tsx`.
