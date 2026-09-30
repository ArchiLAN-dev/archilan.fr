# Story 30.44: Pseudo à titre, légendaire pour les admins, épique pour les adhérents

**Status:** review
**Epic:** 30 - Communauté
**Date:** 2026-09-30 (révisée le 2026-10-01)

## Story

En tant qu'adhérent ou administrateur,
je veux que mon pseudo porte le titre que me donne mon statut, dans les couleurs de rareté des objets de jeu,
afin que mon statut dans l'association se voie d'un coup d'œil sur mon profil et dans la communauté.

## Contexte

Demande de Jean (2026-09-30), à la suite des idées de fonctionnalités pour les adhérents et les admins. Première
version en pseudo holographique (or / argent), jugée trop simple au test ; Jean a choisi sur une page de
comparaison (cinq pistes) la **fusion de deux pistes** :

- **Rareté** : couleurs des objets de jeu, **Légendaire** (orange-or) pour un admin, **Épique** (violet) pour un
  adhérent ; un admin qui est aussi adhérent est légendaire ;
- **Plaque de titre** : le titre écrit **en grand au-dessus du pseudo** avec son emblème (couronne / étoile),
  une barre de lumière qui court sous le pseudo.

Comme pour les images (30.40), le statut est lu **au moment de l'affichage** : rien n'est enregistré, le titre
disparaît quand l'adhésion expire ou que le rôle admin est retiré, et revient avec le statut.

## Critères d'acceptation

1. **Statut** : l'API donne pour chaque pseudo affiché un `nameStyle` : `"legendary"` (admin), `"epic"` (adhésion
   active, pas admin) ou `null` (ni l'un ni l'autre, ou désactivé). Calculé à la lecture, jamais stocké.
2. **Désactivation** : dans la personnalisation, une case « Pseudo à titre » (cochée par défaut, visible seulement
   pour un adhérent ou un admin) permet de garder un pseudo normal. Enregistrée avec le profil (`titledName`,
   booléen ; absent = inchangé ; non booléen = 422 « Valeur invalide. »), aperçu en direct.
3. **Page de profil** : au-dessus du pseudo, le titre en grand avec son emblème (« ♛ Administrateur »,
   « ★ Adhérent ArchiLAN ») ; le pseudo dans les couleurs de rareté, des braises qui montent, une barre de lumière
   qui court dessous.
4. **Cartes** : partout où un pseudo de membre est affiché à côté de sa photo (les mêmes surfaces que 30.42),
   l'emblème devant le pseudo, dans les couleurs de rareté ; la lueur monte au survol ; la hauteur des cartes ne
   change pas.
5. **Lisibilité et accessibilité** : couleur pleine de repli si `background-clip: text` n'est pas pris en charge ;
   « Réduire les animations » : ni braises ni barre qui court ; le titre est masqué aux lecteurs d'écran (les
   badges du profil le disent déjà), le pseudo reste du texte.
6. **Performances** : le statut des cartes d'une liste est résolu en une requête groupée (`activeMemberIds`),
   sans N+1.
7. `composer gates` et `pnpm gates` passent.

## Tasks / Subtasks

- [x] **Task 1** (AC 1, 6) - Règle `NameStyle` (domaine, pure : admin, adhérent, activé -> légendaire / épique /
      null) ; `nameStyle` sur les cartes (requêtes brutes de l'annuaire et des classements, via la requête groupée
      des adhésions) et recopie partout où une carte est reconstruite ; profil public et édition ; tests.
- [x] **Task 2** (AC 2) - Colonne (booléen, défaut vrai) + migration, `CommunityProfile::toggleTitledName()`,
      validation dans la mise à jour du profil ; tests.
- [x] **Task 3** (AC 3, 4, 5) - Front : composant `TitledName` (variantes `profile` et `card`) ; branché sur la
      page de profil et sur les cartes ; tests.
- [x] **Task 4** (AC 2) - Case « Pseudo à titre » dans la personnalisation, avec aperçu.
- [x] **Task 5** (AC 7) - Gates et vérification visuelle.

## Dev Agent Record

### TDD

| Test | Rouge avant | Vert après |
|------|-------------|------------|
| `NameStyleTest` (légendaire admin, épique adhérent, rien, désactivé) | enum absente | `NameStyle::for` |
| `CommunityTitledNameTest` (adhérent, admin, ni l'un ni l'autre, adhésion expirée, désactivation + absent = inchangé, refus) | 4 échecs + champs absents | colonne, `toggleTitledName`, `NameStyleResolver`, exposition |
| `titled-name.test.tsx` (nom brut, titre au-dessus sur le profil, emblème sans titre sur une carte, lecture) | écrit avec le composant | `TitledName` |

### Notes

- API : enum `NameStyle` (`legendary` / `epic`, domaine, pure), `NameStyleResolver` (Application, une requête
  groupée `activeMemberIds` par liste) branché dans les requêtes brutes de l'annuaire et des classements ;
  `nameStyle` recopié partout où une carte est reconstruite. Profil public : `nameStyle`. Édition : `titledName`
  et `titledNameStyle` (le style que donne le statut, pour l'aperçu). `CommunityProfile::toggleTitledName()`,
  `titledName` dans la mise à jour (absent = inchangé, non booléen = 422).
- Migrations : `Version20260930200000` ajoute `holo_name` (première version, déjà appliquée sur la base de dev),
  `Version20260930210000` la renomme `titled_name` (pas de retour arrière sur la base de dev).
- Front : `TitledName` + module CSS. Profil : titre en `max(13px, 0.5em)` au-dessus du pseudo (emblème, lettres
  espacées, lueur), pseudo en dégradé de rareté clippé au texte (ombre en `drop-shadow`, un `text-shadow`
  recouvrirait le dégradé), six braises animées, barre de lumière. Carte : emblème + pseudo en couleur,
  `text-overflow: ellipsis`, lueur renforcée au survol du lien de la carte. Branché sur la page de profil, les
  cartes membres, la communauté, les amis, les commentaires, les classements, les participants des parties
  privées ; case « Pseudo à titre » avec aperçu dans la section Identité.
- Historique : pseudo holographique (or / argent, reflet puis quatre couches) écarté au test ; page de
  comparaison de cinq pistes (rareté, plaque de titre, néon, couronné, aura) ; fusion rareté + plaque retenue.
- Vérification visuelle sur une page d'aperçu reprenant la structure de l'en-tête du profil (bureau et mobile) et
  des cartes, instants figés via l'API Web Animations.
- Le site n'a qu'un thème sombre (`color-scheme: dark`).

### Gates

- `composer gates` : OK (2550 tests, 15149 assertions).
- `pnpm gates` : typecheck, lint (0 erreur), 671 tests, build OK.
