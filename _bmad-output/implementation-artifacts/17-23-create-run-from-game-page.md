# Story 17.23 - Créer une partie depuis la page publique d'un jeu

## Status

Done

## Story

**En tant que** visiteur qui vient de découvrir un jeu sur le site,
**je veux** lancer une partie avec ce jeu en un clic depuis sa page,
**afin de** ne pas avoir à retenir son nom, aller dans mes parties, en créer une, puis le rechercher.

## Contexte

La page publique d'un jeu (`/jeux/{slug}`) est aujourd'hui une impasse : elle décrit le jeu, explique
comment l'installer, et s'arrête là. L'utilisateur qui veut y jouer doit repartir vers `/runs`, créer
une partie, puis retrouver le jeu dans le sélecteur. C'est le moment où l'intention est la plus forte
et où on ne propose rien.

Les deux briques existent déjà : `POST /api/v1/runs` crée une partie en `draft`, et la sélection de
jeu par participant affecte un jeu à un slot. Cette story les relie.

## Acceptance Criteria

**AC1 - Un bouton d'action** sur `/jeux/{slug}` crée une partie dont le slot du créateur porte déjà
ce jeu, et redirige vers la page de la partie.

**AC2 - Rien n'est créé si le jeu ne peut pas être ajouté.** Un seul appel serveur porte la création
et l'affectation, et le jeu est **validé avant** que quoi que ce soit ne soit écrit : un jeu refusé
renvoie 422 sans laisser de partie vide derrière lui.

Formulation volontairement plus faible que « une seule transaction » : les dépôts `Run` et
`RunParticipant` appellent `flush()` dans leur `save()`, donc il y a bien deux écritures. Les
regrouper exigerait de changer le contrat des dépôts, hors périmètre ici. La garantie qui compte -
aucune partie orpheline sur un jeu invalide - est obtenue par l'ordre de validation, et elle est
couverte par un test.

**AC3 - Visiteur non connecté.** Le bouton reste visible et renvoie vers la connexion **avec un
retour sur la page du jeu**, pour que l'intention ne soit pas perdue en route. Aucune restriction de
membre : `POST /runs` exige un compte authentifié, pas un membre à jour.

**AC4 - Un jeu qu'on ne peut pas ajouter ne mène pas à une impasse.** Le bouton n'est pas affiché
sur un jeu désactivé par un admin (story 11.4). Un apworld en échec de préflight est refusé côté
serveur avec le message existant, remonté tel quel sous le bouton - voir Dev Notes pour pourquoi ce
cas n'est pas traité à l'affichage.

**AC5 - Titre par défaut modifiable.** La partie est nommée d'après le jeu (« Partie {nom du jeu} »)
et reste renommable ensuite comme n'importe quelle autre.

**AC6 - L'ISR n'est pas cassée.** `/jeux/{slug}` est rendue en ISR (`revalidate = 300`) et servie en
cache à tous : aucun état propre à l'utilisateur ne doit entrer dans le HTML mis en cache.

**AC7 - Quality gates.** `composer gates` et `pnpm gates` verts.

## Tasks / Subtasks

- [x] Task 1: `POST /api/v1/runs` accepte un `gameId` optionnel ; le service de création affecte le
      jeu au slot du créateur dans la même unité de travail (AC1, AC2).
- [x] Task 2: validation du `gameId` - jeu inexistant, jeu non disponible, apworld en échec de
      préflight → erreur métier typée, pas une 500 (AC4).
- [x] Task 3: composant client `CreateRunWithGameButton` sur la page de détail (AC1, AC3, AC6).
- [x] Task 4: redirection post-connexion vers la page du jeu (AC3).
- [x] Task 5: tests API - création avec jeu, jeu inconnu, jeu en échec de préflight, utilisateur non
      authentifié (AC1, AC2, AC4).
- [x] Task 6: gates (AC7).

## Dev Notes

**Pourquoi un endpoint étendu plutôt que deux appels enchaînés.** La variante « le bouton appelle
`POST /runs` puis l'endpoint de sélection de jeu » est plus rapide à écrire mais ouvre une fenêtre
où la partie existe sans son jeu : si le second appel échoue (réseau, onglet fermé), l'utilisateur
atterrit sur une partie vide sans comprendre pourquoi, et le site se remplit de brouillons
orphelins. Un `gameId` optionnel sur la création règle le problème par construction et respecte
AC-A4 (une commande = une transaction).

**L'ISR est la contrainte structurante.** La page est mise en cache 5 minutes pour tout le monde
(story 34.4). Le bouton doit donc être un composant client qui lit l'état d'authentification côté
navigateur ; ne jamais rendre « Créer une partie » ou « Se connecter » côté serveur, sinon la
première version rendue est servie à tous les visiteurs suivants.

**Le verdict de préflight n'est pas exposable à moindre coût - hypothèse initiale démentie.** Le
plan était d'ajouter le verdict au DTO public, au motif qu'un bouton qui échoue au clic est pire
qu'un bouton absent. Vérification faite, le verdict **n'est pas en base** : il vient de
l'orchestrateur via `RunnerGateway::fetchApworldPreflights()`, indexé par hash d'apworld. L'exposer
imposerait un appel HTTP sortant dans le chemin de lecture d'une page publique mise en cache 5
minutes, pour un cas rare. Arbitrage retenu : `disabled` (une colonne, gratuite) masque le bouton,
et le préflight est tranché au clic avec le message déjà rédigé de la story 9.42. Si les échecs de
préflight deviennent fréquents, persister le verdict sur `Game` serait le vrai correctif.

**Portée.** Uniquement la page de détail d'un jeu. Le même bouton sur la liste `/jeux` transformerait
la page en champ de boutons pour un gain d'intention bien plus faible ; à reconsidérer plus tard sur
la base de l'usage réel.

**Hors périmètre.** Le choix des options du jeu (YAML), l'invitation d'autres joueurs et le
lancement de la génération restent le parcours existant sur la page de la partie. Cette story
raccourcit l'entrée, elle ne remplace pas le flux.
