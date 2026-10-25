# Story 41.48: Une console du blind test qu'on comprend

**Status:** review
**Epic:** 41 - Pelles
**Date:** 2026-10-25
**Dépôts :** `ArchiLAN-dev/archilan-blind-test` (app) et `archilan.fr` (site, liste des événements)
**Origine :** Jean le 2026-10-25, après un « Événement introuvable » dû à une partie chargée sous un identifiant
inventé : « un panel de connexion simple qui met le token dans l'URL, et reprendre surtout toute l'UI / UX, perso
j'y comprends rien ».

## Story

En tant qu'animateur du blind test,
je veux entrer dans la console par un écran de connexion, choisir l'événement dans une liste et être guidé étape par
étape jusqu'à la partie,
afin de préparer et suivre le blind test sans connaître les identifiants, le YAML ni le fonctionnement interne.

## Critères d'acceptation

### Connexion

1. Sans session, `/admin`, `/regie` et `/ecran` affichent un formulaire « Code d'animation » (au lieu d'un texte
   « fermée »). Il envoie le code en GET (`?token=`) ; un bon code ouvre la session et redirige vers la même page
   **sans** le code dans l'adresse ; un mauvais code réaffiche le formulaire avec « Code incorrect ». Les liens
   `?token=` existants marchent toujours. « Se déconnecter » ferme la session d'animation.

### Choix de l'événement

2. Le site expose `GET /api/v1/integrations/blind-test/evenements`, signé comme les autres appels de l'app (corps
   vide) : les événements publiés ou en cours, avec `id`, `title`, `startsAt`, `endsAt`, `blindTestEnabled`.
3. La console commence par « Quel événement ? » : la liste du site (titre, dates, blind test ouvert ou non sur le
   site, partie déjà chargée dans l'app), un clic choisit. L'événement de la partie active est proposé d'office.
   Si le site ne répond pas, un champ « identifiant de l'événement » reste possible, avec un avertissement.
4. L'événement choisi reste affiché en tête (titre, dates) avec « Changer d'événement ». Plus aucun identifiant à
   taper dans le cas normal.

### Préparer (onglet)

5. Quatre étapes numérotées, chacune avec son état (à faire, fait, à revoir) et une seule action principale :
   1. **Playlist** : « Importer depuis le wiki » avec une durée au choix (2 h, 12 h, 24 h, 48 h ; réglages fins
      repliés) ou « Ouvrir un fichier » ; affiche le nombre de morceaux et la durée.
   2. **Relire** (facultatif) : explique quoi relire (alias, type des œuvres « à préciser ») ; l'éditeur YAML est
      replié par défaut.
   3. **Vérifier les vidéos** : progression, puis « Retirer les N vidéos mortes » ; la date de la dernière
      vérification est gardée (et oubliée dès que la playlist change).
   4. **Lancer** : « Charger pour la partie » ; si une partie de cet événement a déjà démarré, l'avertissement et la
      case « Recommencer du début » apparaissent avant le bouton.
6. Les problèmes de la playlist s'affichent en français dans l'étape concernée, pas en vrac.

### En direct (onglet)

7. Un bandeau dit l'état en une phrase et quoi faire : « À l'antenne : morceau 12 sur 900 », « En attente : ouvre la
   régie et clique sur Démarrer », « Régie perdue : le PC de la sono ne répond plus depuis 40 s, vérifie que la page
   Régie est ouverte ». Si la partie active est celle d'un autre événement, il le dit.
8. Les réponses du morceau en cours, puis les chiffres (joueurs, sources trouvées, crédits envoyés), puis les
   dernières trouvailles. Les crédits n'apparaissent en alerte que s'il y en a en attente (« le site ne répond pas,
   nouvel essai chaque minute » + « Renvoyer maintenant ») ou refusés (« plafond atteint ou inscription retirée »).
9. Liens « Ouvrir la régie » et « Ouvrir l'écran de salle » dans un nouvel onglet, en tête de console.

## Tâches

- [x] Site : requête signée de la liste des événements, route, test fonctionnel.
- [x] App : `SiteEvents` (client signé), formulaire de connexion et déconnexion, redirection sans le code.
- [x] App : `checkedAt` du brouillon (migration), vue de la console réécrite (choix de l'événement, onglets, étapes,
      bandeau d'état).
- [x] Tests : connexion (bon, mauvais code, redirection), liste des événements (stub du site), étapes.
- [x] Vérification dans Chrome.

## Dev Agent Record

- Site : `BlindTestEvents` (query signée, corps vide signé `{timestamp}.`), route
  `GET /api/v1/integrations/blind-test/evenements` ; fonctionnels en CI (Postgres local éteint).
- App (branche `feature/41-48-console-ux`, posée sur `feature/41-47-design`) : `AnimatorLogin` devant `/admin`,
  `/regie` et `/ecran` (formulaire GET, redirection sans `token`, autres paramètres gardés), `POST /admin/deconnexion` ;
  `SiteEvents` + `EventChoices` (les parties gardées sous un identifiant que le site ne liste pas apparaissent aussi,
  signalées) ; `playlist_draft.checked_at` (migration `Version20261025140911`) ; console réécrite (choix de
  l'événement, onglets Préparer / En direct, 4 étapes avec état, bandeau d'état en une phrase).
- Le lien principal « Se connecter avec archilan.fr » passe en `.button.primary` : `a.button` est désormais secondaire.
- Vérifié dans Chrome (site local injoignable, donc mode dégradé avec saisie à la main) : connexion (mauvais code, bon
  code, adresse nettoyée, déconnexion), liste des événements, étapes, onglet En direct.
- La partie de test `essai-console` (laissée par la 41.46 dans la base locale, cause du « Événement introuvable ») a été
  supprimée de la base de dev.
