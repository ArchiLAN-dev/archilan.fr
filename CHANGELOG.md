# Changelog

Toutes les versions notables d'archilan.fr sont documentées dans ce fichier.

Le format suit [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/) et le
projet adopte le [versionnage sémantique](https://semver.org/lang/fr/).

## [0.20.0] - 2026-08-28

Les réglages groupés d'un jeu peuvent enfin se choisir dans une liste, même quand l'apworld ne dit
rien de ce qu'ils acceptent.

### Ajouté

- **Un administrateur déclare ce que les réglages groupés acceptent.** Certaines options Archipelago
  rassemblent plusieurs réglages dans un même bloc - vitesse du texte, style de combat, mode des
  boutons - et rien n'oblige un jeu à dire ce que chacun accepte. La plupart ne le disent pas : le
  joueur se retrouvait devant une colonne de champs libres, à devoir savoir de tête qu'un style de
  combat vaut `shift` ou `set`. La page d'administration d'un jeu permet désormais de saisir ces
  valeurs une fois pour toutes, réglage par réglage, et les joueurs obtiennent des listes
  déroulantes. Une case « Liste complète » indique que rien d'autre n'est accepté, ce qui retire
  l'entrée « Autre… » du menu ; sans elle, la saisie libre reste possible. Une valeur déjà
  enregistrée par un joueur est conservée dans tous les cas, y compris si elle sort de la liste :
  une déclaration faite après coup ne réécrit jamais une configuration existante.
- **La déclaration survit aux mises à jour du jeu.** Elle est rangée à part de ce que le fichier de
  jeu raconte de lui-même, si bien qu'un nouveau téléversement ou une ré-introspection actualise
  l'un sans effacer l'autre. Là où les deux se prononcent sur le même réglage, la déclaration
  humaine l'emporte, réglage par réglage : renseigner le style de combat ne masque pas ce que le
  jeu savait dire de la vitesse du texte dans le même bloc. Un bouton rend l'option au fichier de
  jeu.

### Notes de déploiement

- Migration `Version20260828120000` : une colonne `dict_option_values` sur `game`. Jouée
  automatiquement par le service `api-migrations` au démarrage.
- Aucune autre image que celles du monorepo n'est concernée : orchestrateur, archipelago et bridge
  restent où ils sont.

## [0.19.0] - 2026-08-28

Une commande pour rafraîchir l'introspection d'un apworld sans avoir à le re-téléverser.

### Ajouté

- **Ré-introspecter un jeu sans retrouver son fichier.** L'introspection d'un apworld - ce qui donne
  à l'éditeur les types d'options, les bornes des plages numériques et la liste des locations - ne
  tournait qu'une seule fois, au téléversement. Quand l'introspection elle-même évolue, comme en
  `v0.18.0`, les jeux déjà en base gardent donc l'ancienne réponse pour toujours, et le seul recours
  était de remettre la main sur le `.apworld` d'origine pour le téléverser à nouveau - alors que le
  serveur le détient déjà, à l'octet près. `app:games:backfill-option-types --reintrospect` demande
  désormais au serveur de régénérer l'introspection à partir de sa propre copie, puis de la relire.
  `--game=<slug>` limite l'opération à un seul jeu. L'option n'est pas active par défaut : chaque
  ré-introspection lance un conteneur qui charge tout Archipelago, donc un passage sur le catalogue
  entier est long. Un jeu qui échoue est signalé et le balayage continue avec les suivants - le
  serveur laisse alors son introspection précédente intacte, ce qui vaut mieux que de la perdre.

### Notes de déploiement

- Nécessite l'orchestrateur en `0.17.0` : la commande s'appuie sur un point d'entrée qui n'existe
  pas dans les versions antérieures.
- C'est cette commande qui remplace le re-téléversement manuel annoncé dans les notes de la
  `v0.18.0` pour faire apparaître les listes de valeurs de l'éditeur.

## [0.18.0] - 2026-08-28

Trois nouveautés - une pour les administrateurs, une pour la communauté, une pour l'éditeur de
configuration - et une correction d'épinglage sur le déploiement.

### Ajouté

- **Un administrateur peut régler la partie privée d'un autre membre.** Il pouvait déjà l'ouvrir,
  mais l'onglet Réglages n'existait que pour le propriétaire : face à un membre bloqué, il ne
  pouvait que lui demander de corriger lui-même, y compris quand ce membre est précisément celui
  qui n'y arrive pas. Les trois blocs s'ouvrent : surcharge de configuration, seed importée avec
  assignation des slots, et suppression. Les règles d'état ne changent pas - une partie lancée ne
  change pas de seed, une partie active ne se supprime pas, pour l'administrateur comme pour le
  propriétaire. Ce que l'accès en lecture avait fermé reste fermé : lien d'invitation, mot de passe
  de session, journal de génération. Chaque geste est consigné dans le journal d'actions
  administrateur, **rattaché au propriétaire de la partie** et non à l'administrateur : c'est dans
  la fiche du membre qu'on cherchera qui a touché à sa run. Un bandeau prévient l'administrateur
  qu'il intervient chez quelqu'un d'autre et que son action est tracée, parce qu'une interface
  identique à celle du propriétaire invite à oublier de qui est la partie qu'on modifie.
- **Un membre qui diffuse porte un badge Live sur sa carte.** L'annuaire savait dire qu'un membre
  joue, pas qu'il diffuse, alors que le site le fait déjà pour les streams d'une partie. Les membres
  en direct remontent en tête du tri courant - avant la pagination, donc sur l'ensemble des membres
  listables et pas seulement sur la page affichée - et leur carte porte un badge cliquable vers leur
  chaîne. Le badge coexiste avec l'indicateur « en jeu » : diffuser et jouer sont deux choses
  différentes.
- **Un réglage de jeu peut se choisir dans une liste plutôt que se taper de mémoire.** Certaines
  options Archipelago regroupent plusieurs réglages dans un même bloc - vitesse du texte, style de
  combat, mode des boutons - et l'éditeur n'avait que des champs libres à leur offrir : le joueur
  devait savoir de tête que le style de combat accepte `shift` ou `set`. Quand l'apworld déclare
  lui-même les valeurs acceptées, l'éditeur en fait une liste déroulante, et uniquement pour les
  réglages réellement déclarés. La liste garde toujours une entrée « Autre… » : une déclaration
  exacte n'est pas forcément exhaustive, et la fermer transformerait une information juste en
  contrainte fausse. Une valeur déjà enregistrée hors liste est conservée telle quelle, jamais
  ramenée à un défaut. Peu de jeux déclarent ces valeurs aujourd'hui ; ceux qui ne le font pas
  gardent exactement l'éditeur qu'ils avaient.

### Déploiement

- **L'épinglage des images tient maintenant sur celles qui tournent, pas seulement sur celles qui
  sont tirées.** Les exemples de configuration annonçaient « images épinglées, jamais suivies en
  latest » puis laissaient `AP_IMAGE`, `ARCHIPELAGO_GENERATE_IMAGE` et `BRIDGE_IMAGE` en `latest`.
  Or ce sont celles-là que l'orchestrateur instancie à chaque session : le service
  `archipelago-image` du compose ne fait que *tirer* la version épinglée, il ne dit pas laquelle
  sera exécutée. Épingler l'une sans l'autre faisait tourner en production une version
  potentiellement différente de celle qu'on croyait avoir déployée. Les exemples et la
  documentation de déploiement disent désormais la même chose. **Les fichiers `envs/*.env` du
  serveur ne sont pas versionnés : la même correction est à y appliquer à la main.**
- Le dossier `output-run/`, qui ne contient que des artefacts de génération locale, est désormais
  ignoré par git.

## [0.17.4] - 2026-08-28

Correctif : les options valant `on` ou `off` arrivaient à Archipelago en `True` et `False`.

### Corrigé

- **Un réglage `on` / `off` reste ce qu'il est jusqu'à la génération.** L'éditeur écrit le YAML avec
  une bibliothèque qui suit **YAML 1.2**, où `on`, `off`, `yes` et `no` sont des chaînes ordinaires,
  donc écrites sans guillemets. Archipelago le relit avec PyYAML, resté en **YAML 1.1**, où ces
  mêmes mots sont des booléens. Un `battle_scene: on` partait donc d'ici correct et arrivait là-bas
  en `True` - d'où le `ValueError: invalid battle scene: "False"` sur Pokemon Platinum. Le fichier
  était valide des deux côtés : ce sont les deux versions de la norme qui divergent, et c'est nous
  qui écrivons. Ces scalaires sont désormais cités à la sortie, valeurs, clés et éléments de liste,
  ce qui les fait lire comme du texte par les deux versions.

## [0.17.3] - 2026-08-28

Correctif de la v0.17.0 : une partie créée depuis une seed importée ne pouvait pas démarrer.

### Corrigé

- **Un administrateur voit enfin quelque chose sur la vue d'ensemble d'une partie privée.** Tous
  les blocs de cet onglet sont réservés au propriétaire ou aux participants, et la ligne d'état qui
  sert de repli excluait les parties en brouillon et en veille - parce que la carte « mes jeux »
  couvre ces deux cas, pour ceux qui l'ont. Un administrateur n'étant ni l'un ni l'autre, son écran
  était entièrement vide. Il voit désormais l'état de la partie, son nombre de participants, et le
  fait qu'il n'y participe pas.
- **Une partie hébergeant une seed importée démarre enfin.** Le lancement exigeait qu'au moins un
  participant ait déclaré un jeu - ce qui est le fonctionnement normal d'une partie générée ici.
  Mais sur une seed importée personne n'en déclare : les slots viennent de l'archive, c'est tout
  l'intérêt. Le bouton restait donc grisé, et l'API refusait avec `games_required`. Les deux gardes
  reconnaissent désormais une seed importée comme une source de slots légitime. Une partie
  ordinaire, elle, garde son garde-fou.

## [0.17.2] - 2026-08-28

Version de publication : la v0.17.1 n'a livré aucune de ses trois images.

### Sécurité

- **Une advisory OpenSSL bloquait la publication des trois images.** `CVE-2026-14456` (déni de
  service par consommation mémoire non bornée) touche `libcrypto3` et **est corrigée en amont** :
  Alpine publie `3.5.8-r0`, les images embarquaient `3.5.7-r0`. Aucune exemption ici, donc, mais un
  `apk upgrade` qui s'exécute réellement. Les deux images de l'API avaient déjà la ligne, servie
  depuis une couche mise en cache par le workflow ; elle a été modifiée pour forcer la
  reconstruction. L'image du frontend, elle, ne l'avait tout simplement pas : `node:26-alpine`
  servait la version vulnérable et rien ne la mettait à jour. **Sans cette version, aucune image
  `0.17.1` n'existe sur le registre et la prod ne peut pas quitter la 0.17.0.**

## [0.17.1] - 2026-08-28

Correctif de la v0.17.0 : les valeurs nommées d'une option de plage étaient devenues invisibles.

### Corrigé

- **Les valeurs nommées d'une option de plage sont de nouveau visibles et modifiables.** Archipelago
  écrit ce type d'option avec des valeurs nommées à côté de ses nombres - `the_end`, `use_percentage
  _option` - et la v0.17.0 a commencé à les reconnaître pour ce qu'elles sont, ce qui a rendu la
  saisie d'un nombre fixe enfin possible. Mais l'éditeur ne savait dessiner que les lignes numériques
  et les quatre valeurs aléatoires : la valeur nommée disparaissait de l'écran tout en gardant le
  poids du gabarit, et repartait telle quelle dans la configuration enregistrée. Le joueur modifiait
  les nombres, la valeur nommée continuait d'être tirée, et rien à l'écran ne l'expliquait. Elles
  ont désormais leur propre section, avec leur poids éditable. Au passage, les tirages aléatoires
  paramétrés (`random-range-0-360`) souffraient du même angle mort depuis toujours et s'affichent
  eux aussi.

## [0.17.0] - 2026-08-27

Quatre nouveautés autour des parties et des indices, et une correction de fond sur qui est
réellement connecté à un slot.

### Ajouté

- **Un slot peut être joué à plusieurs.** Certains jeux ne se jouent pas seuls : un Minecraft, c'est
  rarement une personne devant son écran, et Archipelago n'a qu'un slot par monde. Jusqu'ici un seul
  des joueurs existait pour la plateforme - les autres n'avaient ni patch, ni indices, ni locations
  d'objets, et ne marquaient aucun point. Le propriétaire d'une partie privée désigne désormais qui
  joue chaque slot, sans limite de nombre, et **tout le monde marque pleinement** : la partie, les
  objectifs et les checks comptent pour chacun, dans l'XP comme au classement et dans les succès. Un
  slot partagé est nommé par tous ses joueurs, et non plus par un seul.
- **Une partie privée peut héberger une seed générée ailleurs.** Une seed faite sur le site
  Archipelago, par un membre en local ou par un autre groupe est un fait accompli : la regénérer
  donnerait un autre multiworld. On peut maintenant l'importer, et l'archive devient la partie -
  aucun YAML n'est demandé, aucune génération n'est lancée. Le créateur assigne les slots de
  l'archive aux participants. Contrepartie dite explicitement par un bandeau : la progression
  détaillée (checks faisables, sphères, détail des objets) n'est pas disponible, car la calculer
  demande les configurations des joueurs, que l'archive ne contient pas. Tout le reste fonctionne :
  progression chiffrée, feed, timeline, indices, fichiers et récap.
- **Les indices se filtrent par priorité et par côté.** Un indice pouvait déjà être classé
  prioritaire, faible ou à éviter, et ce classement remontait au serveur Archipelago - mais tout
  retombait dans la même liste, si bien que sur une partie à quarante indices l'information qu'on
  venait d'y ranger était celle qu'on ne retrouvait plus. Deux filtres s'ajoutent au tri existant et
  se croisent avec lui : la priorité, et le côté - un objet qui vient vers moi, ou un objet caché
  chez moi qu'un autre joueur attend. Rien n'est filtré à l'ouverture.

### Corrigé

- **La présence d'un slot dit enfin qui y joue.** Le paquet d'entrée d'Archipelago liste tous les
  joueurs du multiworld, pas les clients connectés. Le bridge le lisait comme une présence : tous
  les slots passaient « connecté » dès qu'il s'attachait, et seul un départ en retirait un. La
  présence se calcule désormais sur les arrivées et départs réels, en excluant les clients qui
  regardent sans jouer - le bridge lui-même, ou un tracker ouvert à côté du jeu. Un slot joué à deux
  ne se libère qu'au départ du second.
- **Une option `NamedRange` accepte une valeur numérique fixe.** L'éditeur ne recevait pas le type
  des options et le devinait d'après la forme de la valeur ; une option offrant une valeur nommée à
  côté de ses nombres échouait au test « toutes les clés sont numériques » et se retrouvait traitée
  comme une liste de choix, sans champ numérique. Le type introspecté fait maintenant autorité, et
  la valeur nommée survit à l'aller-retour au lieu d'être perdue. Les dictionnaires de réglages
  littéraux, longtemps confondus avec des distributions pondérées, sont eux aussi reconnus pour ce
  qu'ils sont.

### Notes de déploiement

- Les images `archipelago`, `orchestrateur` et `bridge` doivent partir **avant** l'API : l'import de
  seed s'appuie sur une lecture de multidata que seul le conteneur Archipelago sait faire.
- `php bin/console app:games:backfill-option-types` repeuple la table des types du catalogue existant
  sans attendre le prochain téléversement de chaque apworld.

## [0.16.5] - 2026-08-24

Version de publication : la v0.16.4 n'a pu livrer que deux de ses trois images.

### Sécurité

- **Deux advisories de plus sur `kin-openapi` bloquaient la publication de `api-web`.** Elles portent
  toutes deux sur `openapi3filter`, le validateur de requêtes OpenAPI de FrankenPHP : un déni de
  service par corps `multipart/form-data` malformé, et une explosion mémoire par paramètre
  `deepObject`. Aucun des deux chemins n'est atteignable ici, l'image tournant sur le Caddyfile livré
  par FrankenPHP, qui n'active pas cette validation, et Symfony assurant routage et validation.
  FrankenPHP 1.12.7 reste sa dernière version, donc aucun correctif amont n'existe : les deux CVE
  rejoignent les exemptions datées de `.trivyignore`, à réexaminer avec les précédentes le
  2026-10-01. **Sans cette version, `api-web:0.16.4` n'existe pas sur le registre et la prod ne peut
  pas quitter la 0.16.3.**

## [0.16.4] - 2026-08-24

Deux correctifs et deux nouveautés autour des parties : le temps réel qui décrochait au bout d'une
heure, l'accès admin aux parties privées, l'état « en veille » repensé, et des liens de
téléchargement partageables pour les fichiers de sortie.

### Corrigé

- **La page de progression ne décroche plus au bout d'une heure.** Les jetons d'abonnement au temps
  réel vivent une heure, et le hub ne les vérifie qu'à l'abonnement : un flux déjà ouvert survivait
  donc à l'expiration du sien, et le problème n'apparaissait qu'à la première coupure passé ce
  délai. À ce moment-là la page rouvrait le flux avec le jeton de sa toute première connexion, que
  le hub rejetait ; le navigateur abandonne définitivement sur un refus, et la page relançait
  indéfiniment le même jeton périmé. Seul un rechargement en sortait. Un jeton neuf est désormais
  frappé avant chaque reconnexion, sur les onze flux concernés.
- **Un administrateur peut ouvrir les parties privées listées dans la fiche d'un membre.** Le
  backoffice listait ces parties, les liait, savait les arrêter et télécharger leur spoiler, mais la
  lecture refusait tout appelant qui n'était ni propriétaire ni participant : le lien de sa propre
  interface répondait « partie introuvable ». L'admin lit désormais la page sans en devenir
  propriétaire - le lien d'invitation, le mot de passe de session et le journal de génération
  restent fermés.

### Ajouté

- **Les fichiers de sortie ont un lien de téléchargement partageable.** Un clic droit sur un fichier
  copie son adresse, et le destinataire le télécharge sans compte ArchiLAN : de quoi envoyer son
  patch à qui doit jouer le slot. Le lien est signé et ne vaut que pour le fichier qu'il nomme ; la
  multidata et le spoiler restent hors de portée. Il n'expire pas, pour qu'une partie reprise trois
  semaines plus tard ne demande pas un nouveau lien.

### Modifié

- **L'état « en veille » d'une partie privée a été repensé.** Le bandeau de reprise remonte au-dessus
  des onglets, puisque c'est la seule action utile dans cet état, et la suppression descend dans les
  réglages où elle cesse de peser plus lourd à l'œil. Surtout, une partie sans sauvegarde exploitable
  ne se relance plus d'un simple clic : relancer efface la progression de tous les participants, ce
  que l'ancienne interface présentait exactement comme la reprise anodine. Elle demande désormais une
  confirmation qui nomme la conséquence. Les durées d'inactivité passent en jours au-delà de la
  journée, au lieu d'annoncer « inactif depuis 243h ».

## [0.16.3] - 2026-08-22

Trois correctifs de la zone slots : les indices étaient inaccessibles à tout non-admin, la carte de
fin de partie nommait le mauvais joueur, et la fiche utilisateur du backoffice s'affichait sans
marge.

### Corrigé

- **Les indices redeviennent accessibles aux joueurs.** Le contrôle de possession d'un slot
  comparait le numéro de slot Archipelago de l'URL au rang du jeu dans la liste d'un participant.
  Ce rang vaut 1 pour le premier jeu de *chaque* joueur, alors que le slot Archipelago n°1 est
  toujours le spectateur `_bridge_observer` injecté à la génération : la condition ne pouvait donc
  jamais être vraie. Six routes renvoyaient 403 à tout non-admin, sur les runs privées comme sur
  les sessions d'événement - liste des indices, flux temps réel, achat d'un indice, changement de
  priorité, indice sur un objet et locations d'un objet. Seul le contournement administrateur la
  faisait passer, ce qui explique que le défaut soit passé inaperçu. Le numéro est désormais
  résolu vers son nom de slot, la clé de correspondance que le reste du code utilise déjà.
- **La carte de fin de partie nomme le joueur à qui le slot appartient**, et non plus le pseudo du
  compte en train de regarder. Le nom de slot ne pouvait pas servir de repli : il vaut le `name:`
  du YAML du joueur quand celui-ci en a posé un, et ne dérive du pseudo qu'à défaut. Une route
  dédiée rend la correspondance nom de slot vers pseudo du propriétaire, avec le pseudo
  communautaire et repli sur le nom de compte.
- **La fiche utilisateur du backoffice retrouve ses marges.** Le shell d'administration n'en pose
  aucune, chaque page apportant les siennes ; c'était la seule des dix-neuf routes à n'en poser
  aucune, et elle s'affichait collée à la barre latérale et au haut de la fenêtre.

### Sécurité

- **L'image `api-web` récupère à son tour les correctifs de sa base Alpine.** Même garde que celle
  posée sur `api-worker` en 0.16.2, appliquée avant que le piège ne se referme : sa base est
  récente, donc l'effet est nul aujourd'hui, mais un rebuild déclenché pour une autre raison ne
  repartira plus sur des paquets périmés.

## [0.16.2] - 2026-08-20

Version de publication : la v0.16.1 n'a pu livrer que deux de ses trois images.

### Sécurité

- **L'image `api-worker` récupère les correctifs de sécurité de sa base Alpine.** Les paquets de
  `php:8.5-cli-alpine` vieillissent avec le tag auquel l'image est épinglée, et le cache de couches
  du workflow de publication gelait le résultat de `apk add` tant que le Dockerfile ne changeait
  pas. Le worker est ainsi resté sur `postgresql-libs 18.4-r0`, et le gate Trivy a bloqué la
  publication de la v0.16.1 sur la vague de CVE PostgreSQL 18.5 (36 HIGH, toutes corrigées en
  amont). Un `apk upgrade` est ajouté en tête de l'image. **Sans cette version, `api-worker:0.16.1`
  n'existe pas sur le registre et la prod ne peut pas quitter la 0.16.0.**

## [0.16.1] - 2026-08-20

Correctif de la page de progression : les checks annoncés accessibles et le nombre d'items reçus
étaient tous deux faux, pour deux raisons indépendantes.

### Corrigé

- **Le tracker annonçait des checks accessibles qui ne l'étaient pas.** Une sauvegarde Archipelago
  range les items reçus par un slot sous deux clés, `(team, slot, remote_items=True)` qui contient
  tout et `(team, slot, remote_items=False)` qui ne contient que ce qui vient des autres joueurs.
  La lecture concaténait les deux listes : chaque item reçu était compté deux fois, et toutes les
  règles de logique à compteur (`state.has(item, joueur, n)`) devenaient trop permissives, pour
  tous les jeux. Sur une run The Wind Waker, un unique `Progressive Bow` lu comme deux satisfaisait
  les flèches de feu et de glace, et six locations injouables étaient présentées comme faisables.
  **Nécessite les images `archipelago:0.12.3` et `bridge:0.11.1`.**
- **Le compteur « items reçus » comptait les lignes de la liste, pas les items.** Le bridge groupe
  les items par nom avec une quantité : cinq Pieces of Heart formaient une ligne. Un slot détenant
  44 items sur 36 noms affichait 36. Le pourcentage de progression cumulait le même défaut et un
  dénominateur qui comptait deux fois tout nom partiellement reçu, soit 22 % affichés pour 15 %
  réels.

## [0.16.0] - 2026-08-15

Cette version débloque les runs privées dont le propriétaire est absent, et ferme la publication
d'images Docker portant des vulnérabilités corrigeables.

### Ajouté

- **Tout participant peut relancer une run privée mise en veille.** Un propriétaire absent bloquait
  la partie de tout le monde : la reprise après le watchdog d'inactivité lui était réservée. Le
  périmètre est volontairement limité à la reprise depuis l'état en veille. Le premier lancement fige
  la configuration et les slots de tous les participants, et l'arrêt coupe la partie des autres :
  ces deux actions engagent autrui et restent au propriétaire.

### Sécurité

- **Les trois images publiées sont désormais bloquées en cas de CVE corrigeable.** Le scan Trivy
  passe de simple avertissement à gate sur `frontend`, `api-worker` et `api-web`, et il s'exécute
  avant la publication. Aucune image ne peut plus partir sur le registre avec une vulnérabilité
  HIGH ou CRITICAL pour laquelle un correctif existe.
- **npm est retiré de l'image `frontend`.** Les trois HIGH signalés au build de la 0.15.0 ne
  venaient pas de nos dépendances mais de l'arbre que npm embarque dans `node:26-alpine`, hors de
  portée du lockfile comme des overrides. Le runtime n'appelle jamais npm.
- **`api-web` bascule sur la variante Alpine de FrankenPHP** (1.12.7), ce qui élimine 19 HIGH hérités
  de la base Debian et 8 des 10 CVE du binaire Go. Les 2 findings restants n'ont pas de correctif
  amont et portent sur des chemins de code que l'application n'emprunte pas ; ils sont consignés en
  exemptions datées, à réexaminer avant le 2026-10-01.

### Modifié

- **La suite de tests fonctionnels ne reconstruit plus le schéma avant chaque test.** Il est
  construit une fois par process, les tests se contentant de vider les lignes. La suite passe de
  8 min 36 à 2 min 17 sans qu'aucun test soit modifié, et le temps de CI backend de 11,9 min à
  6,4 min.
- **La boucle de test locale peut tourner en parallèle** (24,6 s sur huit processus), chacun sur sa
  propre base. Le gate faisant autorité et la CI restent volontairement en série, à l'identique.
- Montée des dépendances frontend, dont Next.js 16.3.0.

Rien à faire au déploiement : cette version ne demande ni migration, ni changement de configuration.

## [0.15.0] - 2026-08-15

Cette version rouvre l'accès en clair aux serveurs Archipelago, refermé par erreur, et livre le
surfaçage des adresses de connexion mesurées sur des clients réels.

### Ajouté

- **Les runs acceptent `ws://` et `wss://` sur le même port.** Un second routeur TCP non chiffré par
  session, sur le même entrypoint et vers le même service que le routeur TLS. Traefik oriente chaque
  connexion vers l'un ou l'autre selon qu'il reconnaît un ClientHello, sans détection à configurer.
  L'adresse déjà affichée aux joueurs sert les deux schémas : ni le contrat de l'API ni l'interface
  ne changent.
- **Les pages de run affichent les formes d'adresse réellement testées** : hôte et port séparés,
  adresse jointe, et URI complète, chacune étiquetée selon le type de client qui l'attend. Il
  n'existe pas de chaîne unique qui fonctionne partout, et c'est un constat mesuré sur des clients
  tiers, pas une précaution.

### Corrigé

- **Les clients Archipelago sans TLS étaient exclus des runs depuis le 2026-08-14.** La fermeture du
  port en clair s'appuyait sur une décision d'architecture qui n'avait jamais été prise. Les clients
  incapables de parler TLS, dont des mods de jeu embarquant leur propre client Archipelago,
  recevaient un HTTP 404 sans explication : le proxy ne trouvait aucun routeur non chiffré et
  repassait la connexion à son handler HTTP par défaut.
- **L'éditeur YAML ne perd plus les blocs imbriqués** des options de type dictionnaire.
- **Le récapitulatif privé d'une run redevient accessible** à son propriétaire et à ses
  participants. Le rendu serveur restant anonyme, la reconnaissance se fait côté client.
- **Le script de contrôle d'avant-migration Traefik inspecte aussi les conteneurs arrêtés**, angle
  mort qui pouvait passer pour un feu vert.

### Modifié

- **Traefik v3.6.1 devient le minimum documenté**, et non plus « une v3 quelconque ». En deçà, le
  provider Docker ne négocie pas la version d'API du démon : le proxy démarre, sert le TLS, et
  répond 404 à tout - pour l'ensemble des services de l'hôte, pas seulement les nôtres.
- Configuration de la phase 2 de la fermeture du port du bridge, côté orchestrateur. **Cette version
  ne bascule rien** : le drapeau reste à tourner explicitement, et seulement après avoir vérifié
  qu'une run existante répond toujours.

## [0.14.2] - 2026-08-14

Version courte, mais elle corrige une perte de données silencieuse.

### Corrigé

- **L'archivage d'une run ne perd plus l'état des slots.** Le job d'archivage appelait le bridge sur
  `http://localhost:{port}` alors qu'il tourne dans le conteneur `api-worker`, où `localhost`
  désigne le conteneur lui-même. L'appel ne pouvait pas aboutir ; il était avalé par un `catch` qui
  journalisait un simple avertissement. **Chaque run terminée archivait donc une liste de slots
  vide**, et l'archive n'étant écrite qu'une fois, la perte était définitive.

### Modifié

- **L'API joint le bridge d'une session par le réseau interne** (`archilan-bridge-{sessionId}:5000`)
  au lieu de sortir vers l'adresse publique du serveur pour atteindre un conteneur voisin. Un seul
  composant construit désormais cette adresse ; il y en avait quinze, et le seizième - celui de
  l'archivage - avait divergé sans que personne le voie.
- `BRIDGE_HTTP_HOST` disparaît, devenue inutile.

Le port du bridge reste publié sur l'hôte : sa fermeture demande une modification de
l'orchestrateur, prévue après la bascule vers l'accès `wss://`. **La plage `25000-25099` ne doit
donc pas encore être filtrée au pare-feu.**

## [0.14.1] - 2026-08-13

Version d'infrastructure, requise avant la bascule vers l'accès `wss://`. Elle corrige surtout une
erreur de cible : le dépôt décrivait un reverse proxy qui n'a jamais été déployé.

### Corrigé

- **Le nom du certresolver n'est plus codé en dur.** L'API annonçait `letsencrypt` à Traefik, alors
  que le proxy de production déclare `https`. Un nom inconnu ne provoque aucune erreur visible :
  Traefik sert son certificat par défaut et le navigateur refuse la connexion **sans interstitiel**.
  Le nom vient désormais de `TRAEFIK_CERT_RESOLVER` (défaut `https`). **Sans cette version, la
  bascule échoue sur les certificats.**

### Modifié

- **`traefik/` supprimé du dépôt.** Ce répertoire décrivait une configuration jamais déployée - ce
  qui explique le `${ACME_EMAIL}` qu'il contenait, jamais interpolé et que personne n'avait
  remarqué. Le proxy réel sert plusieurs projets de l'hôte, vit hors du dépôt et se configure en
  arguments de ligne de commande. **La manipulation annoncée dans la 0.14.0 (générer `traefik.yml`
  avant de redémarrer) est donc caduque : il n'y a plus rien à générer sur le serveur.**
- **`scripts/gen-traefik-entrypoints.sh`** remplace l'ancien générateur : il n'écrit aucun fichier,
  il imprime le fragment à coller dans le compose du proxy.
- **`docker-compose.prod.yml` remis en accord avec la machine** : réseau `traefik`, certresolver
  `https`, images épinglées par version, `api-migrations`, routage MinIO, services d'images.

### Ajouté

- **Un test qui gate le contrat de nommage des entrypoints.** Le générateur et l'API doivent
  s'accorder sur `ap-{port}` ; une divergence rend les runs injoignables en paraissant saines.
  Vérifié en cassant volontairement la convention.
- **`scripts/traefik-v3-preflight.sh`** : inventorie les libellés Traefik de tous les conteneurs de
  l'hôte et signale les formes que Traefik v3 ne comprend plus. Le passage en v3 est un prérequis
  de la chaîne, l'option `headers` du provider HTTP n'existant pas en v2.
- **`docs/deploiement-production.md`** et **`docs/traefik-runs-archipelago.md`** : topologie réelle,
  ordre de bascule, retour arrière, signatures de diagnostic, et les écarts dépôt/machine restants.

## [0.14.0] - 2026-08-13

Version de l'administration : la fiche d'un membre, jusqu'ici éclatée entre plusieurs écrans,
devient un seul endroit d'où l'on voit et d'où l'on agit. Elle pose aussi, sans rien changer de
visible, l'infrastructure qui rendra les serveurs Archipelago joignables depuis un navigateur.

### Ajouté

- **La fiche d'un membre, en un seul écran (stories 36.1 à 36.6)** : identité et rôles, adhésion et
  inscriptions, modération, volet jeu, journal d'activité du compte, et les actions ciblées sur ses
  objets. Répondre à « que se passe-t-il avec ce membre ? » demandait jusque-là d'ouvrir quatre
  pages et de recouper soi-même.
- **`/communaute` devient un hub (story 30.38)** : la page listait ; elle oriente désormais vers les
  joueurs, les runs et les événements. La recherche, le tri et le filtre amis de `/joueurs` ont été
  rendus composables au passage - ils s'excluaient mutuellement sans raison.

### Infrastructure

- **Accès `wss://` aux serveurs Archipelago, chaîne technique (epic 37, stories 37.1 à 37.4)** :
  entrypoints Traefik générés depuis la plage de ports, provider HTTP branché sur l'API, un routeur
  TCP par run avec certificat réel, fin de la publication en clair du port de chaque run, et
  l'adresse chiffrée ajoutée au contrat de l'API. **Rien n'est visible pour un joueur tant que la
  bascule n'a pas été faite en production**, et l'affichage de l'adresse (story 37.5) attend la
  matrice de compatibilité des clients web tiers.

  **Cette version demande une intervention manuelle au déploiement.** `traefik/traefik.yml` est
  désormais **généré** depuis `traefik.yml.tpl` et n'est plus versionné : un `git pull` le supprime
  du serveur. Il faut lancer `./scripts/gen-traefik-config.sh` **avant** tout redémarrage de
  Traefik. La procédure complète, l'ordre imposé de bascule et les pièges constatés sont dans
  `traefik/README.md`.

### Corrigé

- `js-yaml` épinglé en `>=4.3.1` pour purger la CVE-2026-59870.
- Le sous-titre des événements décrit ce que la liste montre, au lieu de décrire l'interface.
- `/joueurs` utilise le composant `Switch` partagé.

## [0.13.0] - 2026-08-05

Version consacrée aux listes de jeux du joueur - ce qu'il possède, ce qu'il veut essayer - et à
leur présence partout où l'on choisit un jeu. Elle lève aussi une contrainte qui n'avait jamais été
un choix : une partie privée était obligée d'avoir un mot de passe.

### Ajouté

- **Déclarer qu'on possède un jeu, sans Steam (story 28.13)** : le filtre « mes jeux » ne reposait
  que sur le couplage Steam, qui ne peut reconnaître qu'un titre portant un `steamAppId`. Une
  grande partie du catalogue n'en a pas - un jeu GameCube, SNES ou N64 était donc **structurellement
  impossible** à marquer comme possédé. Une liste rattachée au compte comble ce trou ; les deux
  sources sont unies à la lecture, jamais fusionnées en base, si bien que recoupler Steam ne peut
  pas effacer un marquage manuel.
- **Une deuxième liste, « à essayer » (story 28.14)** : « mes jeux » répond à ce qu'on peut lancer,
  pas à ce qu'on veut découvrir. Les deux listes partagent un stockage, pas une sémantique - un jeu
  peut appartenir aux deux, et vouloir un jeu ne fait jamais croire au catalogue qu'on le possède.
- **Les deux listes au moment de choisir (stories 28.15, 28.16)** : dans la sélection de jeux d'une
  partie privée et dans celle d'une inscription à un événement. Une liste d'envies qu'on ne peut pas
  consulter en décidant est une liste qu'on remplit une fois et qu'on ne rouvre jamais.
- **Une partie privée peut se passer de mot de passe (story 16.13)** : elle est déjà privée par son
  lien d'invitation, et le mot de passe Archipelago était une seconde barrière imposée. Le laisser
  vide donne désormais un serveur ouvert, au lieu d'un mot de passe aléatoire que personne n'avait
  demandé.

### Modifié

- **Le filtre du catalogue devient un sélecteur de liste** : « mes jeux » et « à essayer » sont deux
  réponses à la même question, donc exclusives l'une de l'autre. L'URL porte `liste=mes-jeux` ou
  `liste=a-essayer` ; l'ancien `mes-jeux=1` reste lu pour que les liens partagés continuent de
  fonctionner.
- **La sélection de jeux d'une inscription rejoint les deux autres** : elle n'avait qu'une recherche,
  et qui ne regardait que le nom. Elle gagne les plateformes, les deux listes, et une recherche qui
  couvre aussi la description. La charge utile de l'API expose désormais `platforms` et
  `steamAppId`, sans quoi aucun de ces filtres n'était calculable.

### Corrigé

- **Une partie sans mot de passe restait bloquée en « lancement »** alors que son serveur tournait et
  acceptait les connexions (story 16.13) : le passage à l'état « en cours » exigeait un mot de passe
  non vide. La garde datait d'une époque où il y en avait toujours un, et aucun test ne pouvait
  l'atteindre. Trouvée en lançant une vraie partie.
- **Deux failles de sécurité dans `guzzlehttp/guzzle`** (CVE-2026-69246, CVE-2026-69245) : hôte non
  canonique contournant les contrôles d'hôte, et domaine de cookie non canonique conservant sa portée
  de sous-domaine.
- **Accords en français** : « Runs hebdos » plutôt que « Runs hebdo », et « run » au féminin partout -
  la moitié du site l'écrivait déjà ainsi, les pages hebdomadaires étaient restées en arrière.

## [0.12.0] - 2026-08-03

Version centrée sur deux chantiers : rendre visibles les échecs de génération multiworld, qui
plantaient jusqu'ici sans que personne ne sache pourquoi, et refondre le récap de fin de partie
pour qu'il raconte ce qui s'est réellement joué plutôt que ce que la seed contenait.

### Ajouté

- **Diagnostic des échecs de génération (epic 9, stories 9.38-9.44)** : les erreurs de génération
  sont analysées et attribuées au joueur fautif, qui est notifié avec le propriétaire de la partie ;
  un apworld est testé seul à l'upload comme à la sélection, avec le verdict affiché sur la
  configuration concernée ; l'enregistrement d'échec est structuré côté générateur ; les mondes
  Archipelago sont désormais chargés « honnêtement », sans stubs masquant les vraies dépendances
  manquantes (70 mondes chargés, 2 modules réellement absents contre l'intégralité auparavant).
- **Administration des apworlds (stories 9.45-9.47)** : le template YAML par défaut d'un jeu est
  éditable et réinitialisable depuis l'apworld, les plateformes d'un jeu sont surchargeables à la
  main, et l'onglet ApWorld a été réorganisé.
- **Refonte du récap (stories 32.15-32.19)** : bandeau de chiffres clés, tableau comparatif des
  joueurs qui remplace le podium et les cartes de superlatifs, filtre « progression uniquement » et
  balance des échanges, marqueurs d'indices et temps morts sur la timeline, objets les plus échangés
  et qualité des envois par joueur.
- **Créer une partie depuis la page d'un jeu (story 17.23)** avec ce jeu déjà sélectionné, et
  **renommer une partie** (story 17.24), ce qui n'était possible d'aucune façon jusqu'ici.
- **Filtre « mes jeux » automatique** au couplage explicite d'une bibliothèque Steam (story 28.11).
- **Repli de la page Progression** quand le bridge est indisponible (story 17.21) et **indices en
  clair sur l'overlay de log** (story 29.6).
- **Masquage par champ des informations de connexion** (stories 17.21, 17.22) sur les parties
  privées, les sessions d'événement et les runs hebdomadaires : copie possible sans révéler.

### Modifié

- **Le graphe des échanges devient un diagramme de flux** (story 9.49). Un layout à force dirigée
  sert à révéler la structure d'un réseau dense ; une partie ArchiLAN compte 2 à 6 slots, où il
  produisait deux ronds et deux flèches superposées. Chaque slot est dédoublé en expéditeur et
  destinataire, ce qui rend le graphe acyclique et fait apparaître les objets gardés en local.
- **La page de résultats d'une run est retirée** au profit du récap (story 32.20), qui la couvrait
  déjà entièrement. La route `GET /api/v1/runs/{id}/results` disparaît avec elle - **changement de
  contrat cassant**, les liens partagés hors du site renvoient un 404.
- **Vocabulaire unifié** : « checks complétés » partout, au lieu de « checks trouvés » sur le seul
  récap.

### Corrigé

- **Le graphe des échanges ne s'affichait plus du tout** (story 9.48) : le lecteur filtrait sur un
  type d'événement que le bridge n'envoie jamais, si bien que tous les objets étaient écartés. Les
  tests validaient le défaut, leurs fixtures inventant le même type.
- **Une génération plantait sur les jeux au nom numérique** (story 9.39, cas « 2048 ») : la clé de
  section YAML était coercée en entier par PHP.
- **Une partie terminée retombait en « inactive »** une vingtaine de secondes plus tard (story
  17.25) : l'arrêt du conteneur, conséquence normale de la fin de partie, écrasait son statut final -
  ce qui masquait son récap et la rendait relançable. Commande `app:runs:repair-finished` pour
  réparer les parties déjà dans cet état.
- **Fuite de confidentialité** (story 32.20) : la page de résultats servait les participants, jeux
  et temps de toute session terminée dont on connaissait l'identifiant, sans authentification -
  runs privées et événements non publics compris, malgré le réglage « récap privé ».
- **Lisibilité daltonienne** de la barre de qualité des envois : trois des cinq catégories étaient
  des gris quasi identiques et les deux autres la paire violet/rouge la plus confondue. Palette
  validée, écart de séparation entre segments, et tous les chiffres écrits en toutes lettres.
- Libellé de l'axe Y du graphe de déroulé, et poids de l'image front (`libvips` embarqué).

### Technique

- Deux migrations additives et réversibles : `session_players_snapshot`, et `platform_families` sur
  `game`.
- `js-yaml` 4 → 5 côté front, plus les lots de dépendances mineures.
- Nouvelles commandes d'exploitation : `app:sessions:rebuild-recap`, `app:runs:repair-finished`.

## [0.11.0] - 2026-07-29

Version de fonctionnalité centrée sur les récaps de partie (epic 32 au complet) : chaque
partie terminée d'un événement public a désormais sa page de récap publique et sa timeline
interactive, rejouable après coup comme en direct. S'y ajoutent une passe mobile sur les
surfaces de partie, l'enrichissement du sitemap (récaps et profils publics) et une grosse
correction de poids des images de la page d'accueil.

### Ajouté

- **Récaps publics de partie (stories 32.1-32.5)** : page `/parties/{id}` avec podium,
  chronologie des objectifs, graphe « qui a envoyé quoi à qui », superlatifs et VOD ;
  index des parties par événement ; carte de partage Open Graph ; récap des runs
  personnelles (publiable par leur propriétaire) ; succès communautaires dérivés des
  superlatifs.
- **Timeline de partie (stories 32.6-32.14)** : le feed de jeu est persisté (objets, puis
  indices et objectifs) et alimente des courbes de checks par joueur - consultables en
  direct comme après la partie. Marqueurs d'objectif et d'objets de progression, options de
  vue (par intervalle/cumulé, checks trouvés/objets reçus, joueurs séparés/confondus),
  recherche insensible aux accents et facettes du journal (reçus/envoyés/locaux/indices/
  objectifs), zoom tactile via une barre de sélection, rattrapage automatique après une
  coupure du direct.
- **Sitemap enrichi (stories 34.1 addendum, 34.8)** : les récaps publics et les profils des
  joueurs ayant choisi l'audience « public » sont désormais énumérés (nouvel endpoint
  anonyme dédié), avec des dates de modification réelles.
- **Désactivation d'un jeu du catalogue (story 11.4)** côté admin.
- **Garde-fou de poids des images statiques** : un test échoue si un fichier de
  `public/images` dépasse 500 Ko.

### Modifié

- **Passe mobile des surfaces de partie** : contrôles de vue de la timeline en sélecteurs
  natifs sous la largeur tablette, barres d'onglets défilables horizontalement (page de
  partie et backoffice session), graphe plus haut sur téléphone, facettes repliables.
- **Images de la page d'accueil recompressées** : la photo hero passe de 10,6 Mo à 128 Ko et
  le logo de 2,4 Mo à 51 Ko (~13,4 Mo de poids de page économisés ; le LCP mobile mesuré
  passait de 95 s avec les fichiers bruts).

### Corrigé

- **Timeline** : coordonnées NaN du sélecteur de plage à l'arrivée d'événements en direct ;
  deux bugs de synchronisation sélecteur/zoom (grille de temps changée sous un zoom actif,
  poignées jointes) détectés en revue avant merge.
- **Profil communautaire** : le formulaire ne perd plus les modifications en cours de saisie
  (#391).
- **Overlays** : statistiques de célébration d'objectif et tri des checks atteignables
  (#393) ; les échecs d'upload vers le stockage sont désormais journalisés (#389, #392).

## [0.10.0] - 2026-07-23

Version de fonctionnalité axée sur le contenu éditorial et l'affichage public :
rédaction en Markdown sur les surfaces admin et communautaires, refonte des
tutoriels d'installation (Markdown, vidéos, accordéon), et plusieurs améliorations
de l'interface publique (page jeu, profil, navigation). Inclut aussi les correctifs
livrés en hotfix depuis la 0.9.0 et une passe de sécurité sur les dépendances.

### Ajouté

- **Rédaction Markdown (story 10.10)** : un moteur de rendu Markdown partagé et un
  éditeur léger (barre d'outils + aperçu) sont désormais disponibles sur les champs de
  contenu rédigés par les admins comme par les membres. Le rendu émet des éléments React
  (jamais une chaîne HTML), donc le HTML brut saisi reste du texte inerte - aucune
  injection possible sur les surfaces communautaires.
- **Vidéos dans le Markdown (story 10.11)** : une URL de vidéo seule sur sa ligne se
  transforme en lecteur embarqué durci (`youtube-nocookie`, `sandbox`), sur toutes les
  surfaces Markdown.
- **Description orientée Archipelago sur un jeu (story 3.13)** : champ optionnel affiché
  en pleine largeur sur la page publique du jeu, en complément de la description générale.
- **Onglet Notes admin sur la fiche jeu (story 3.12, #303)** : notes internes visibles des
  seuls administrateurs.
- **Badges de statut des participants (story 30.37, #261)** sur la page de détail d'une run.
- **Menu compte dans la navigation (story 10.13)** : les actions de compte (profil, espace,
  admin, déconnexion) se regroupent sous un menu avatar déroulant affichant la photo de
  profil communauté, avec un lien direct vers le profil public.
- **Tutoriels en accordéon** : chaque étape se replie/déplie ; repliées par défaut, elles se
  referment aussi quand on coche leur progression.

### Modifié

- **Profil communautaire public par défaut (story 30.28)** : un nouveau compte est visible
  par défaut. Les profils existants ne sont pas modifiés (rien ne distingue en base un choix
  délibéré de l'ancien défaut). Un `PUT` partiel ne réécrit plus l'audience omise.
- **Bio déplacée dans la carte d'identité** du profil, titre « À propos » retiré.
- **Largeurs de page centralisées derrière des tokens (story 10.12)** : toutes les pages
  applicatives s'alignent sur la largeur de `/jeux` ; la prose longue reste plus étroite pour
  la lisibilité. Le rail en-tête/pied a son propre token.
- **Tutoriels d'installation (story 31.11)** : les liens et images d'étape sont désormais
  intégrés directement dans la description Markdown (un champ au lieu de trois). Migration de
  données non destructive ; les images uploadées sont servies sous une URL stable.
- **Page jeu - en-tête** : la description Archipelago passe en pleine largeur ; le titre et la
  description habillent la jaquette (mise en page flottante) et reprennent en pleine largeur
  sous elle.
- **Affichage des tutoriels** : taille de titre d'étape distincte du corps, couleur de corps
  de texte dédiée, alignement de la case de progression.

### Corrigé

- Erreur réelle du bridge remontée au lieu d'être masquée (#278).
- Débordement du pied de la carte de progression joueur (#245).
- Pulsation « En jeu » rognée par l'avatar (#299).
- Run terminée ou annulée passée en lecture seule (#338).
- Patches d'entrée hebdomadaire servis depuis l'archive durable, plus depuis un port de bridge
  réutilisé (#262).
- Propriété du slot vérifiée sur les endpoints d'indice et d'item-location (#252, #253).
- Paragraphes réels préservés dans la variante bloc du Markdown ; garde de type d'étape ne
  rejetant plus toutes les étapes.

### Sécurité

- Avis de sécurité des dépendances résolus : `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `sharp`,
  `fast-uri`, `next`, `brace-expansion`.

## [0.9.0] - 2026-07-19

Version de fonctionnalité ciblée : autocomplétion des noms de locations dans
l'éditeur YAML de configuration de slot, livrée de bout en bout sur toute la
chaîne (introspection apworld, orchestrateur, SDK PHP, API, frontend). Le reste
est de l'hygiène documentaire BMAD, sans impact sur le code.

### Ajouté

- **Autocomplétion des champs de location (story 4.14)** : les options de type liste
  `priority_locations`, `exclude_locations` et `start_location_hints` de l'éditeur YAML
  proposent désormais des suggestions de noms de locations issues de la liste statique de
  l'apworld. Les suggestions sont un simple indice - le texte libre reste toujours accepté
  (jamais de validation stricte), et le champ redevient un input simple quand le jeu n'a
  pas de données de location (dégradation gracieuse). Chaîne complète : `introspect_options.py`
  (archipelago) expose les noms de locations, l'orchestrateur les publie via
  `GET /apworlds/{hash}/locations`, le SDK `orchestrator-client` v1.3.0 ajoute `getLocations`,
  l'API persiste `Game.locationNames` et l'expose dans les payloads de sélection de jeu.
- **Commande de backfill des locations** : `app:games:backfill-locations` remplit
  `location_names` pour les jeux déjà introspectés (miroir du backfill des types d'options).

### Modifié

- **Hygiène des statuts BMAD** : clôture des statuts de l'Epic 4 (story 4.14 débloquée et
  livrée ; stories 4.15-4.19 déjà implémentées repassées de `review` à `done`) et
  réconciliation des statuts périmés du backlog avec la réalité livrée.

## [0.8.0] - 2026-07-19

Version majeure de consolidation : référencement (SEO) complet des pages publiques,
page publique de récap de session, et un vaste durcissement de l'architecture backend
(chemin d'écriture strict CQRS, règles DDD gatées, migrations de runtime). Aucun
changement de comportement visible côté utilisateur sur les fonctionnalités existantes ;
l'essentiel est interne (typage, standards, outillage) ou orienté visibilité.

### Ajouté

- **Référencement (SEO) des pages publiques (epic 34)** : `sitemap.xml` et `robots.txt`,
  couverture des métadonnées et hygiène des URL canoniques, données structurées JSON-LD,
  régénération incrémentale (ISR) sur les pages publiques avec optimisation des images,
  passe de performance web et d'hygiène de crawl, passe éditoriale et mots-clés, plus
  l'outillage de mesure.
- **Bucket média public dédié (story 34.4)** : des URL d'images stables pour les visuels
  publics (événements, articles), indépendantes des URL présignées à durée de vie courte.
- **Page publique de récap de session (epic 32)** : projection de récap, graphe des
  échanges entre joueurs et superlatifs, exposés sur une page publique.

### Modifié

- **Chemin d'écriture strict CQRS (epic 35)** : les échecs de commande lèvent désormais
  des exceptions applicatives typées mappées centralement en réponses HTTP (Stage 1), et
  toute méthode de commande renvoie `void`, un enregistrement `final readonly` ou un enum,
  jamais un tableau brut (Stage 2), invariant vérifié par le validateur d'architecture.
  Corps HTTP inchangés.
- **Standards et architecture DDD (epic 33)** : validateur d'architecture étendu (finalité,
  pas de setters publics sur les agrégats, pas d'entité Doctrine renvoyée par l'Application,
  gating `ROLE_MEMBER`), taxonomie de sous-dossiers appliquée à tous les contextes, injection
  de `ClockInterface` (fin des lectures d'horloge en Application), adoption de Rector et de
  `phpstan-strict-rules`, extensions `phpstan-symfony`/`phpstan-doctrine`, et passes de
  bonnes pratiques Symfony 7 et React 19 / Next 15.
- **Migration de la couche données du frontend vers TanStack Query** et **couche SSE typée**
  (gardes partagées, hooks conscients des gardes).
- **Migrations de runtime** : PHP 8.5, Node 26, TypeScript 6.
- **Documentation-outillage rendue gatée** : les standards documentés ne peuvent plus dériver
  du code réellement appliqué (test de cohérence).

### Corrigé

- **CI** : le gate `pnpm audit` ne casse plus sur l'ancien point d'accès npm retiré ; checks
  obligatoires rendus compatibles avec le filtrage par chemin.
- **Streaming** : durée de vie (TTL) des pannes et démontage des embeds corrigés (dette
  story 7.7).

### Supprimé

- **Infection (mutation testing)** retiré : il ne mesure rien d'exploitable sur PHPUnit 13.
- Code mort et surface de dépréciation fermés lors des passes de bonnes pratiques.

## [0.7.1] - 2026-07-02

Correctif : le nom de slot personnalisé du joueur est désormais respecté de bout en
bout, avec un durcissement de l'accès aux fichiers de patch.

### Corrigé

- **Nom de slot personnalisé (story 9.37)** : le nom saisi dans « Nom en jeu » (le
  YAML `name:`) est désormais utilisé par le serveur Archipelago au lieu d'être
  remplacé par le nom dérivé `{pseudo}_{jeu}`. Repli sur le nom dérivé quand le nom est
  vide ou invalide ; unicité entre slots et longueur maximale de 16 préservées.
- **Attribution des fichiers de patch** : durcissement de l'attribution (plus long nom
  correspondant parmi tous les slots de la session) afin qu'un nom personnalisé préfixe
  d'un autre ne puisse pas donner accès au patch d'un autre joueur.

### Modifié

- **Liens Twitch et Discord externalisés en variables d'environnement**, pour ne plus
  les coder en dur.
- Refonte du README racine (présentation vitrine du projet) et fiabilisation de la CI
  (checks obligatoires rendus compatibles avec le filtrage par chemin).

## [0.7.0] - 2026-06-28

Itération profil et configuration de parties : identité de compte personnalisable,
espace « Mon compte » repensé, priorité des indices, et plusieurs corrections sur
l'éditeur YAML et le cycle de vie des parties.

### Ajouté

- **URL de profil personnalisable (story 2.10)** : choix de son identifiant public
  (`/joueurs/{slug}`) depuis l'espace compte, avec un changement tous les 30 jours,
  réservation de l'ancien identifiant pour son propriétaire et possibilité de revenir
  à son URL précédente (reclaim) sans attendre.
- **Espace « Mon compte » repensé (story 30.36)** : navigation par routes avec un
  tableau de bord vue d'ensemble, remplaçant l'ancien système d'onglets.
- **Priorité des indices (stories 9.34 / 9.35)** : les joueurs peuvent définir la
  priorité de leurs checks directement depuis la page des indices, propagée à
  Archipelago.
- **Verrouillage de l'édition après génération (story 17.20)** : une fois la partie
  générée, la sélection des jeux, l'édition des YAML et la configuration des jeux sont
  verrouillées.
- **Streams Twitch des participants (story 7.7)** : association d'un stream Twitch par
  participant et par session.
- **Modale de description d'option (story 4.18)** : la description complète d'une option
  s'ouvre dans une fenêtre défilante depuis l'éditeur YAML.

### Corrigé

- **Options dict littérales / `game_options` (stories 4.17, 9.33)** : correction du crash
  de génération causé par une mauvaise interprétation des dictionnaires d'options ;
  parsing en dictionnaires libres et verrouillage des clés des options à schéma fixe,
  appuyés sur une table de types d'options faisant autorité.
- **Rendu des options dict en lecture seule (story 4.19)** : affichage lisible des
  dictionnaires dans la vue YAML (plus de `[object Object]`).
- **Ordre alphabétique des jeux (story 28.10)** : tri insensible à la casse des listes de
  jeux, y compris dans la bibliothèque admin (`/admin/jeux`).
- **Validation des noms de slot/joueur (story 9.36)** : caractères alphanumériques,
  underscore et placeholders Archipelago autorisés, longueur maximale de 16.
- **`session.ready` au redémarrage** : l'état remonté par l'orchestrateur fait désormais
  autorité après un redémarrage de partie.

### Modifié

- Normalisation des em-dashes en tirets simples dans l'ensemble du dépôt.

## [0.6.0] - 2026-06-22

Itération communautaire : succès enrichis (epic 30), pages détaillées des parties
privées (epic 17) et unification du calcul de niveau/XP.

### Ajouté

- **Succès communautaires enrichis (epic 30)** :
  - succès récents mis en avant sur le profil joueur, plus une page catalogue
    dédiée affichant le taux de rareté de chaque succès (« X % des joueurs l'ont ») ;
  - règles de succès basées sur l'atteinte d'un objectif en événement, configurables
    de façon générique ou par événement précis ;
  - image personnalisée optionnelle à la place du trophée par défaut ;
  - attribution et révocation manuelles d'un succès à un joueur par un admin.
- **Page détail d'un participant** (partie privée) : page dédiée en lecture seule
  avec l'identité communautaire (pseudo + avatar), le niveau/XP et les statistiques,
  les jeux du participant, et la configuration YAML appliquée présentée en deux
  vues (visuelle et textuelle).
- **Navigation enrichie des parties privées** : liens vers les profils et les pages
  jeux, cartes participants plus détaillées.
- **Mode streamer** : les informations de connexion d'une partie sont masquées par
  défaut sur les pages de partie.

### Corrigé

- **Niveau/XP incohérent** : le niveau et l'XP sont désormais calculés de façon
  unique sur toutes les surfaces (profil, page participant, `/communaute`), en
  incluant aussi bien les parties hebdomadaires que les événements.
- **Parties hebdomadaires** : les weekly runs complétées sont comptées dans
  l'historique et la vitrine du profil joueur.
- **Arrêt auto des parties privées** : `autoShutdown` est verrouillé au profil de
  type et ne peut plus être neutralisé par un override de partie périmé - une partie
  privée inactive s'arrête de nouveau correctement.
- **Onglets communauté** : affichage d'un squelette animé pendant le chargement.

### Technique

- Recalcul de sécurité (backstop) des succès toutes les heures.
- Convention projet : plus aucun em-dash dans le code, la documentation ou les
  messages de commit (normalisation en tirets simples).

## [0.5.1] - 2026-06-21

Hotfix : lot de correctifs remontés après la v0.5.0.

### Corrigé

- **`/compte` accessible sans authentification** : la page est désormais protégée
  (redirection vers `/connexion` tant qu'aucun utilisateur n'est connecté).
- **Runs hebdomadaires absentes des statistiques** : les weekly runs complétées
  comptent maintenant dans les stats globales (communauté) et dans les stats du
  profil joueur.
- **Avatars** : le classement (`/classements`) et l'en-tête de `/compte` affichent
  la photo de profil au lieu de l'initiale du pseudo.
- **Dashboard admin** : la carte « Actualités » n'est plus marquée « Bientôt »
  (la fonctionnalité est en place).
- **Éditeur YAML** : les options pondérées à valeurs multiples s'affichent
  correctement au rechargement - une valeur par défaut supprimée ne réapparaît
  plus à 0 % et les valeurs personnalisées créées sont conservées.
- **Écran de victoire** : la fanfare est de nouveau jouée lorsque l'objectif est
  atteint via l'événement Archipelago (et pas seulement au déclenchement manuel).
- **Statistiques de fin de run** : en partie privée, le nombre d'items, de checks
  et la date d'atteinte de l'objectif sont enregistrés sur le slot au moment de
  l'objectif (et non plus uniquement à l'archivage).
- **Téléchargement des patchs** : un patch dont le nom est suffixé par l'apworld
  (ex. `…_SHA_SHAR_0.6.7.apshar`) est de nouveau téléchargeable.

## [0.5.0] - 2026-06-20

Itération majeure : profils communautaires enrichis (epic 30) et tutoriels
d'installation Archipelago (epic 31), pages jeux publiques, statistiques par jeu,
et durcissement de la sécurité des dépendances.

### Ajouté

- **Profils communautaires enrichis (epic 30)** : page profil publique
  `/joueurs/[slug]` (avatar + bannière animée, badges de reconnaissance,
  niveau/XP, liens sociaux typés, vitrine, succès, présence « en jeu »,
  commentaires, kudos), annuaire `/communaute` (classement / récents / amis),
  espace `/compte` (édition du profil, navigation groupée). Système d'amis +
  blocage, feed d'activité, notifications in-app (Mercure), succès configurables
  (catalogue en base + moteur de règles + administration) et recalcul
  automatique des succès à la fin d'une run.
- **Avatars** : upload d'une photo de profil + avatars par défaut déterministes,
  frames décoratives et bannières animées.
- **Signalement de profil enrichi** (type + contenu problématique + commentaire)
  et file de modération pondérée par la gravité.
- **Actions de modération de compte** : avertir / suspendre / bannir / lever,
  avec journal d'audit et application au niveau de l'authentification.
- **Tutoriels d'installation Archipelago (epic 31)** : étapes d'installation par
  jeu, rendu public sur `/jeux/[slug]`, guide générique `/aide/archipelago`,
  incitation à l'installation, checklist interactive + médias, contributions
  communautaires modérées, indications de version (apworld + client) et upload
  d'images sur les étapes.
- **Pages jeux publiques** `/jeux/[slug]` (epic 28) et jeux récemment joués.
- Statistiques d'objectifs par jeu (epic 18) et action « Terminer » d'une run par
  son propriétaire (epic 17).

### Modifié

- Le pseudo affiché **partout** est désormais le pseudo communautaire (override
  `community_profile.display_name`, sinon le nom de compte) : annuaire,
  classements, en-tête, commentaires, écrans d'administration et accueil
  `/admin`.
- Refonte de l'éditeur de personnalisation du profil et navigation `/compte` à
  deux niveaux ; regroupement de la navigation d'administration en sections.
- Retrait du bouton « j'aime » sur les succès.

### Sécurité

- Mise à jour des dépendances vulnérables (symfony 7.4.12/7.4.13, guzzle 7.12.1,
  guzzle/psr7, jmespath…) - `composer audit` ne remonte plus aucune alerte.

### Corrigé

- Statistiques : comptage des objectifs par jeu et non par session (story 18.8).
- Divers correctifs d'interface (barre de navigation publique, mise en page de la
  modération, en-tête de profil).

### CI / Outillage

- Exécution des quality gates sur les pull requests ; outillage de worktree pour
  les sessions de développement parallèles.

## [0.4.1] - 2026-06-15

Correctif de production sur la connectivité API → bridge des sessions.

### Corrigé

- **Runs hebdomadaires & parties en production** : l'API ne parvenait pas à joindre le
  bridge d'une session lancée (erreur 503 « bridge non disponible » sur la page « ma run »,
  et liste de patchs vide). En production l'API tourne dans un conteneur et ne pouvait pas
  atteindre le port publié du bridge via l'IP publique. Les appels API → bridge passent
  désormais par un host configurable (`BRIDGE_HTTP_HOST`).

### Déploiement

- Nouvelle variable d'environnement **`BRIDGE_HTTP_HOST`** (prod : `host.docker.internal`,
  dev : `localhost`) et ajout de `extra_hosts: ["host.docker.internal:host-gateway"]` sur
  `api-web` / `api-worker` dans `docker-compose.prod.yml`.

## [0.4.0] - 2026-06-15

Itération centrée sur le couplage de la bibliothèque Steam et la refonte de la page Jeux.

### Ajouté

- **Couplage bibliothèque Steam** : sur la page Jeux et sur la sélection de jeux d'une
  partie, l'utilisateur renseigne son compte Steam (URL de profil, pseudo ou SteamID64) pour
  voir, dans le catalogue, les jeux qu'il possède et qui sont jouables à ArchiLAN
  (étiquette « Tu possèdes ce jeu »). Profil privé géré avec un message clair.
- **Compte Steam enregistrable** : les membres connectés peuvent enregistrer leur compte
  Steam (dans l'espace compte et depuis la page Jeux) ; le couplage est alors automatique
  aux visites suivantes.
- **Refonte de la page Jeux** : catalogue chargé côté client avec **recherche instantanée**,
  filtres (disponibilité, « Mes jeux »), tri, et couplage Steam intégré à la grille.
- **Catégories de plateformes** : filtres par familles (Super Nintendo, GameCube, Nintendo 64,
  PC, PlayStation, Switch, Mobile…) déduites d'IGDB, plus une facette « Steam », sur la page
  Jeux et la sélection de jeux d'une partie.

### Technique

- Catalogue enrichi du `steamAppId` et des plateformes IGDB (commandes de backfill
  `app:games:backfill-steam-app-ids` et `app:games:backfill-platforms`).
- Client Steam Web API + endpoint public de couplage ; endpoint catalogue complet
  `GET /games?all=1`.
- Nouvelle variable d'environnement **`STEAM_WEB_API_KEY`** ; migrations
  `game_catalog_sync.steam_app_id`, `game_catalog_sync.platforms`, `user.steam_profile`.

## [0.3.0] - 2026-06-14

Itération centrée sur les indices Archipelago, le cycle de vie des sessions de runs
hebdomadaires et la fiabilité du suivi temps réel.

### Ajouté

- **Indices payants par le propriétaire** : un joueur peut acheter un indice (item ou
  location) avec ses propres points sur sa run hebdomadaire et sur sa partie personnelle ;
  les boutons d'indice « gratuit (admin) » restent réservés aux admins.
- **Indices live sur tous les slots** + commande admin d'indice par item, via la
  data-storage Archipelago.
- **Cycle de vie des sessions de runs hebdomadaires** : la partie hebdo suit désormais le
  même mécanisme que les parties privées - détection d'arrêt du conteneur (idle/stoppé) et
  bouton « Relancer ma partie » ; les pages se rafraîchissent automatiquement (poll adaptatif).
- **Bornes de range introspectées** dans l'éditeur YAML, et validation à la sauvegarde :
  blocage des poids tous à 0 et des valeurs hors `[min, max]` (modes simple, avancé et
  template admin).
- **Runs rejointes** visibles dans « Mes parties ».
- **Réglages de monde « host-gated »** appliqués à la génération.

### Modifié

- **Temps du leaderboard hebdo** compté depuis le **lancement de la partie** du joueur
  (et non depuis la génération du run).
- **Coût d'indice** affiché désormais **autoritaire** (lu depuis Archipelago), corrigeant
  les valeurs gonflées.
- Résilience renforcée de la session et du temps réel sur les pages passives.
- Révocation des refresh-tokens par famille avec fenêtre de grâce anti-rejeu.

### Corrigé

- Un crash de génération/lancement bascule la session en état **échec terminal visible** ;
  une session crashée/bloquée reste **relançable**.
- Chargement des mondes Archipelago lors du calcul de réatteignabilité (worlds Universal
  Tracker comme `pokepark`, et `pokemon_emerald` via `pkg_resources`).

## [0.2.1] - 2026-06-11

Correctif d'un blocage de génération sur les parties privées, plus deux améliorations
autour des fichiers générés (servis depuis le stockage durable MinIO).

### Corrigé

- **Génération des parties privées en template par défaut** : un BOM UTF-8 en tête des
  templates apworld faisait échouer la lecture du YAML (jeu vidé), donc la génération
  échouait et le serveur ne démarrait jamais. Le BOM est désormais retiré (au parse et à
  l'ingestion), les templates existants sont nettoyés, et un échec de configuration n'immobilise
  plus la partie sur « démarrage ».

### Ajouté

- **Téléchargement du spoiler** d'une partie privée par son propriétaire (ou un admin),
  servi depuis le stockage durable - disponible quel que soit l'état de la partie.
- **Patchs des joueurs** d'une partie privée servis depuis le stockage durable : chaque
  joueur récupère le patch de son slot même partie arrêtée/en veille.

## [0.2.0] - 2026-06-11

Deuxième release : refonte du cycle de vie des runs (restart/idle), configuration
de session et overrides, runs hebdomadaires publiques, et une large passe
responsive/UX sur tout le site et l'administration.

### Ajouté

- **Configuration de session (épic 27)** : modèle de configuration côté domaine,
  persistance, overrides par périmètre (hebdo / privé / événement), formulaire
  d'administration, infobulles d'aide, et verrouillage de l'auto-shutdown pour les
  runs privées.
- **Runs hebdomadaires publiques** : navigation publique des runs de la semaine
  et entrée de menu dédiée.
- **Téléchargement des patchs** d'une run perso côté membre.
- **Scheduler de nettoyage (épic 13)** : purge planifiée des données temporaires
  et des logs avec rétention configurable.
- **Bibliothèque de jeux (admin)** : comptage réel des utilisations d'un jeu et
  tri par nom ou par nombre d'utilisations.
- Tri et affichage responsive de la gestion des événements (admin).
- Vitrine des fonctionnalités et slogan sur la page d'accueil.

### Modifié

- **Refonte restart / idle (épic 17)** : suppression du wake-on-connect ; l'idle
  est désormais géré nativement par l'`auto_shutdown` d'Archipelago, et la relance
  se fait manuellement depuis une sauvegarde.
- Rafraîchissement temps réel plus réactif (`staleTime` 5 s → 2 s).
- Slot « Bridge » masqué de l'interface.
- Large passe responsive sur les tableaux et pages d'administration ainsi que sur
  les pages publiques (accueil, runs, navigation mobile/tablette).
- Dependabot cible désormais `develop` plutôt que `main` (respect du Gitflow).

### Corrigé

- Relance d'une session restée à l'état « stopped ».
- Message contradictoire du panneau « idle ».
- Débordements d'affichage : détails de connexion, infobulles, noms de patchs,
  descriptions d'événements, menu mobile, navigation tablette, progression des slots.
- Expiration des images d'événement.
- Formulations diverses (vocabulaire « seed »).

## [0.1.0] - 2026-06-09

Première version publiée d'archilan.fr - le site et l'ERP de l'association
ArchiLAN autour d'Archipelago (multiworld). Cette release inaugure le tag de
version et la publication d'images Docker versionnées sur GHCR.

### Plateforme

- **Site public** : page d'accueil, présentation d'Archipelago, événements,
  catalogue de jeux, actualités, intégration du live Twitch, pages légales
  (mentions, confidentialité, CGU, CGV).
- **Comptes & adhésions** : inscription, connexion, confirmation d'e-mail,
  réinitialisation de mot de passe, espace membre, adhésions et paiements.
- **Événements** : création/édition côté admin, cycle de vie
  (brouillon → publié → en cours → terminé), inscriptions, capacité,
  visibilité publique/privée (accès protégé par mot de passe), sélection de
  jeux par participant, récaps (VOD + article).
- **Runs Archipelago** : sessions de jeu pilotées via l'orchestrateur et le
  bridge, progression des joueurs en temps réel (Mercure/SSE), résultats.
- **Runs privées** : salons privés gérés par leur propriétaire, invitations.
- **Runs hebdomadaires** : templates par jeu, génération de la run de la
  semaine, page « ma run » côté membre, historique.
- **Administration** : tableau de bord, gestion des utilisateurs, du
  catalogue, des actualités, du bot Discord et de la configuration des
  sessions.

### Ajouté

- **Configuration des sessions configurable (epic 27)** : profils de
  configuration serveur & génération par type de session (hebdo / événement /
  privée), avec surcharge par périmètre :
  - hebdo = par template (admin uniquement),
  - événement = par session (admin),
  - privée = par run (propriétaire).
  - Résolution profil ⊕ override champ par champ, propagée jusqu'au serveur
    Archipelago (release/collect, remaining, countdown, anti-triche, coût des
    indices, points par check, compatibilité, arrêt auto, mot de passe,
    plando, mode course, niveau de spoiler).
  - Mot de passe de connexion défini uniquement en override, avec proposition
    aléatoire par défaut.
  - Test E2E hebdomadaire prouvant qu'une option configurée atteint bien le
    serveur lancé.
- **Runs hebdomadaires** : bouton « Générer la run de la semaine » par
  template ; tableau « Items non reçus » sur l'onglet objets côté membre.
- **Accueil dynamique** : la section « Nos événements » affiche désormais les
  vrais événements (à venir et passés) au lieu d'un contenu statique.

### Modifié

- Refonte de la page de configuration des sessions (sections, interrupteurs,
  alignement, mise en page deux colonnes) et du panneau « Configuration
  avancée (override) » : sections cohérentes, valeurs héritées du profil
  affichées, en-tête avec icône.
- Uniformisation des boutons d'action de la page admin des événements.
- Formulation française clarifiée sur les écrans de configuration.

### Corrigé

- **Authentification multi-onglets (story 13.4)** : coordination du
  rafraîchissement proactif des tokens entre onglets pour éviter les
  déconnexions lors de l'ouverture quasi simultanée de plusieurs onglets.
- Attente de la résolution de l'authentification avant redirection sur les
  pages de run hebdomadaire (plus de redirection prématurée au chargement à
  froid).

### CI / Infrastructure

- Publication d'images Docker sur GHCR pour `api-web`, `api-worker` et
  `frontend`, désormais **taguées par version** (`0.1.0`, `0.1`) lors d'un tag
  git `v*.*.*`, en plus de `latest` (sur `main`) et `sha-<court>`.
- Pipelines backend (PHPStan, PHP-CS-Fixer, PHPUnit, validation
  d'architecture DDD) et frontend (typecheck, lint, build) sur chaque PR.

[0.2.1]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.2.1
[0.2.0]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.2.0
[0.1.0]: https://github.com/ArchiLAN-dev/archilan.fr/releases/tag/v0.1.0
