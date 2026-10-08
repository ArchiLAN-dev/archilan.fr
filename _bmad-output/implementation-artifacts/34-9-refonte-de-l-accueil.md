# Story 34.9: Refonte de la page d'accueil

**Status:** done
**Epic:** 34 - SEO & visibilité
**Date:** 2026-10-07

## Story

En tant que visiteur qui découvre ArchiLAN, ou membre qui revient,
je veux que l'accueil me dise en un coup d'œil ce qu'est ArchiLAN, ce qui se passe en ce moment et comment
commencer,
afin de passer à l'action (une run, un événement, le Discord, l'adhésion) au lieu de lire une plaquette.

## Contexte

L'accueil date du lancement (épic 34 a surtout travaillé sa forme : metadata, données structurées, polices, images).
Il présente, dans l'ordre : le hero (photo, slogan, boutons événements / Discord / Twitch, chiffres du Discord),
« C'est quoi Archipelago ? » (3 cartes), six cartes de fonctionnalités, les événements à venir et passés, trois
compteurs (runs terminées, checks, objectifs), deux cartes (événements, Twitch), la section Discord (story 30.50),
puis le live Twitch.

Depuis, le site a beaucoup grandi et l'accueil n'en montre presque rien :

- les **runs hebdos** (épic 16) : la run de la semaine, ses jeux, qui la joue ;
- les **quêtes de la semaine** et les **pelles** (épic 41), la **boutique** et les **cosmétiques** (cadres vidéo,
  bannières, titres, couleurs) ;
- les **succès** (épic 30) et les **profils** de joueurs, le **classement** ;
- les **récaps publics** des parties (épic 32) : timeline, superlatifs, moments forts ;
- les **parties privées** entre amis, le **suivi en direct** des slots, l'**aide au YAML** et le catalogue de jeux ;
- l'**association** elle-même : l'**adhésion** et ce qu'elle apporte, les **actualités**.

La page parle surtout de la plateforme en général et peu de ce qui est vivant cette semaine. Elle n'a pas non plus de
parcours « je commence » clair.

## Proposition de structure (à discuter)

1. **Hero** : garder la photo, le slogan et les boutons ; ajouter une ligne « en ce moment » (prochain événement
   avec compte à rebours, ou parties en cours) pour montrer que le site vit.
2. **Commencer en trois étapes** : créer son compte, choisir un jeu et préparer son YAML (lien vers le catalogue et
   l'aide), rejoindre une run hebdo, une partie entre amis ou un événement. Chaque étape mène à sa page.
3. **Cette semaine** : la run hebdo en cours (jeux, nombre de joueurs, bouton « Jouer »), les quêtes de la semaine et
   ce qu'elles rapportent.
4. **Prochain événement** : une carte mise en avant (date, lieu, places restantes, inscription), puis les suivants en
   ligne compacte ; les événements passés renvoient vers leurs récaps.
5. **Ce qui s'est passé** : deux ou trois récaps récents (superlatifs, moment fort, joueurs), pour donner envie.
6. **La communauté** : les compteurs actuels enrichis (joueurs, parties, checks, objectifs, succès débloqués), des
   profils mis en avant (derniers succès légendaires, top de la semaine), et la section Discord.
7. **Faire partie de l'association** : ce qu'apporte l'adhésion (à préciser par Jean), son prix, le bouton ; un mot
   sur la boutique et les cosmétiques qu'on gagne en jouant.
8. **Actualités** : les deux ou trois derniers articles.
9. **ArchiLAN en direct** : le live Twitch quand il y en a un (sinon la section se replie).

« C'est quoi Archipelago ? » et les six cartes de fonctionnalités seraient fusionnées dans l'étape 2 et les
sections vivantes, pour ne pas répéter.

## Critères d'acceptation (provisoires)

1. Un visiteur comprend en moins de dix secondes : ArchiLAN est une association, Archipelago est un randomizer
   multiworld coopératif, et il y a quelque chose à faire cette semaine.
2. Chaque section vivante (semaine, prochain événement, récaps, communauté, actualités) lit des données réelles et se
   replie proprement quand il n'y a rien (pas de section vide, pas de faux contenu).
3. Un parcours « je commence » mène en trois étapes d'un compte neuf à une partie.
4. L'adhésion est présentée avec ses avantages réels et son prix.
5. La page reste rapide : rendu serveur avec ISR comme aujourd'hui, images optimisées, LCP du hero sous 2,5 s en
   mobile (mesure Lighthouse avant et après).
6. Le SEO est conservé ou amélioré : titre, description et données structurées à jour, mots-clés de la carte 34.6
   présents dans les titres de section.
7. Mobile d'abord : toutes les sections lisibles à 390 px, aucun défilement horizontal.
8. Gates verts ; tests des replis (aucun événement, aucune run hebdo, aucun récap).

## Questions pour Jean (avant la maquette)

1. **Le message principal** : on garde « Joue pour toi, gagne pour tous. » et la photo, ou on retravaille aussi le
   hero ?
2. **Pour qui d'abord** : le nouveau venu qui ne connaît pas Archipelago, ou le joueur qui connaît déjà et cherche
   où jouer ? (ça change l'ordre des sections 2 et 3).
3. **L'adhésion** : quels avantages afficher (accès aux événements, cosmétiques, réductions, soutien) et doit-elle
   être haut dans la page ?
4. **Un membre connecté** voit-il la même page, ou une version « pour toi » (sa run en cours, ses quêtes, ses
   pelles) ?
5. **Les récaps et profils mis en avant** : choisis automatiquement (les plus récents, les superlatifs) ou par
   l'admin ?
6. **Ce qu'on retire** : les six cartes de fonctionnalités et la carte Twitch peuvent-elles disparaître ?

## Décisions (2026-10-07)

- Jean : on garde le hero ; priorité au nouveau venu ; maquette « Nouvel accueil ArchiLAN » validée.
- Par défaut (questions 3 à 6 non tranchées) : même page pour tous ; récaps choisis automatiquement (les plus
  récents) ; les six cartes de fonctionnalités, « C'est quoi Archipelago ? », la grille d'événements, le widget de
  compteurs et la carte Twitch disparaissent ; « Voir les événements » devient « Je commence » (ancre).
- Adhésion : sans prix (porté par le formulaire HelloAsso) ; trois avantages vérifiables (cosmétiques réservés aux
  adhérents, soutien aux LAN, site bénévole). « Une voix à l'assemblée générale » attend la confirmation des statuts.

## Tasks / Subtasks

- [x] **Task 1** - Maquette (canvas) ; validation de Jean.
- [x] **Task 2** - Données : `features/home/home-api.ts` (runs hebdo actives, compteurs, récaps des LAN récentes,
  `newestFirst`), revalidées 300 s comme la page (aucune requête `no-store`, la page reste en ISR).
- [x] **Task 3** - Sections : `features/home/home-sections.tsx` (`HomeNow`, `HomeConcept`, `HomeStartSteps`,
  `HomeThisWeek`, `HomeLan`, `HomeRecaps`, `HomeCommunity`, `HomeAssociation`, `HomeNews`), chacune repliée sans
  donnée réelle.
- [x] **Task 4** - Mesure Lighthouse (mobile, médiane de 3) : avant 88 / 100 / 96 / 100, LCP 3,8 s (2026-07-29) ;
  après 96 / 96 / 96 / 100, LCP 2,7 s, 758 Kio (2026-10-07). Les deux points d'accessibilité relevés (contraste du
  surtitre « L'association », libellé du lien Twitch) corrigés ensuite. Détail dans `docs/seo-measurement.md`.
- [x] **Task 5** - `home.test.tsx` ; gates.

## Dev Agent Record

- Pas de nouvelle API : les récaps se lisent par LAN passée (`/events/{id}/parties`), comme le sitemap ; aucun récap
  public n'existe encore en prod (les LAN précèdent l'épic 32), la section reste donc repliée pour l'instant.
- Les trois jaquettes du concept sont celles des runs hebdos de la semaine ; à moins de trois, le texte prend la
  largeur.
- Metadata et données structurées inchangées (titre et description déjà conformes à la carte 34.6).

## Hors périmètre

- La refonte des autres pages (événements, jeux, communauté).
- Une version personnalisée pour le membre connecté, si Jean la veut : story à part (question 4).
