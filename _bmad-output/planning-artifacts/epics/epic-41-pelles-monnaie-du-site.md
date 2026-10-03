# Epic 41: Les Pelles, monnaie virtuelle d'ArchiLAN

**Statut :** proposé le 2026-10-02
**Date :** 2026-10-02
**Origine :** idée de Jean et Cédric (2026-10-01), discutée avec l'équipe sur Discord ; nom choisi par l'équipe :
la **Pelle** (jeu de mots sur archi*pel*, logo de pelle). Réflexion revue le 2026-10-02 à partir de ce que font
Twitch, Steam et Reddit.

## Objectif

Donner aux membres une monnaie virtuelle, **la Pelle**, qu'ils gagnent en jouant, en aidant l'association et
pendant les événements, et qu'ils dépensent sur le site : hints en partie, primes sur des items, cosmétiques.
Elle **remplace les « happenings »** des ArchiLAN, où des coins étaient gérés à la main pour acheter des hints.

## Principes

- **Pas d'argent réel.** Les pelles ne s'achètent pas et ne se convertissent jamais en euros. Le registre est
  conçu pour que ce soit possible un jour, sans s'y engager : relier les pelles à de l'argent ouvrirait des
  questions juridiques et fiscales (monnaie électronique, jeux d'argent, contrepartie de dons).
- **Un registre de mouvements, pas un solde.** Chaque gain et chaque dépense est une ligne (montant, type de
  pelle, motif, source, auteur, date). Le solde est leur somme. Historique pour le membre, audit pour l'admin,
  remboursement par ligne inverse.
- **Séparées de l'XP.** Dépenser des pelles ne fait jamais baisser un niveau. Les gains peuvent suivre les mêmes
  déclencheurs que l'XP, mais ce sont deux compteurs distincts.
- **Gains enregistrés à l'événement, avec une clé anti-doublon** (par exemple « goal du slot X »), jamais
  recalculés : changer un barème ne modifie pas les soldes passés.
- **Deux types de pelles seulement** (leçon de Reddit, qui a supprimé ses 50 récompenses en 2023 parce que le
  fouillis gênait plus qu'il n'aidait) :
  - **pelles en or** : permanentes, utilisables partout ;
  - **pelles d'event** : distribuées pendant un événement, dépensables seulement dans ses parties, et qui
    disparaissent à sa fin.
- **Autant de puits que de sources.** Une monnaie qui s'accumule sans se dépenser perd sa valeur. Les sources
  (gains) et les puits (dépenses, commissions, destructions) sont conçus ensemble, et mesurés dès la première
  story (tableau de bord admin : pelles créées, détruites, en circulation, par semaine).
- **Aucun visuel généré par IA** pour les cosmétiques et les jetons physiques : des dessins faits par des membres
  (règle de l'équipe, 2026-10-01).
- **Solde privé.** Le solde et l'historique ne sont visibles que du membre et des admins : pas de classement des
  plus riches, qui pousserait au farm.
- **Une expiration ou une confiscation est un mouvement, jamais un filtre.** Les pelles d'event qui expirent sont
  détruites par une ligne du registre (motif `event_expired`), pour que l'audit et le tableau de bord de
  circulation restent justes.
- **Compte banni ou supprimé.** Un compte banni ne gagne ni ne dépense (solde gelé, rendu à la levée du
  bannissement). À la suppression d'un compte, ses mouvements restent dans le registre, rattachés à un membre
  anonymisé (`DeleteAccount`), pour que la circulation reste juste.

## Existant réutilisé

- **Hint gratuit en partie** : le bridge sait déjà donner un hint d'objet ou de lieu sans toucher aux points de
  hint d'Archipelago (`rest_hints.py`, `free=true`, stories 9.28 à 9.30). **Ce chemin est réservé aux admins
  à dessein** (story 9.31 : « un joueur ne peut que payer », `PlayerStateController`). Acheter un hint avec des
  pelles ouvre donc un **nouveau chemin serveur** qui débite d'abord, puis appelle le hint gratuit pour le
  compte du joueur : il ne doit jamais rouvrir l'accès direct au mode gratuit, et un joueur ne peut demander de
  hint que pour un slot qu'il joue (issue #253).
- **Config de partie** (epic 27, `SessionServerConfig`) : un réglage « hints contre pelles » s'y ajoute avec les
  mêmes règles de qui le règle (admin pour les hebdos et les events, propriétaire pour les parties privées).
- **Qui a trouvé quoi** : le fil d'événements des récaps (epic 32, `SessionFeedEvent` de type `item-received`)
  garde, pour chaque item reçu, le slot qui l'a envoyé (`sender_slot`, `sender_name`). C'est la base des primes,
  avec des limites à traiter en 41.4 (voir plus bas).
- **Actions admin auditées** sur la fiche utilisateur (epic 36, `AdminUserActionAudit`).
- **Validation des contributions tutoriels** (page « Contributions », epic 39).
- **Notifications** (`Notifier`, epic 30) pour prévenir d'un gain.

## Découpage

Ordre du plus fort au plus faible. Les stories 41.1 à 41.4 remplacent tout de suite les happenings et ne
dépendent pas des barèmes ni des cosmétiques.

| Story | Contenu |
|---|---|
| 41.1 | **Socle** : registre, deux types de pelles, solde, page « Mon portefeuille » (solde et historique), crédit et débit admin audités, tableau de bord de circulation |
| 41.2 | **Distribution pendant un event** : créditer des pelles d'event à tous les inscrits d'un event ou à une sélection ; expiration à la fin de l'event |
| 41.3 | **Hints contre pelles** : réglage de partie (autorisé ou non, prix), achat d'un hint d'objet ou de lieu, remboursement automatique si le hint échoue ; pelles d'event d'abord, puis en or si la partie l'accepte |
| 41.4 | **Primes sur les items** : un joueur bloqué offre des pelles pour un item ; celui qui l'envoie la reçoit automatiquement ; petite commission détruite ; prime remboursée si la partie se termine sans l'item. Points durs listés plus bas |
| 41.5 | **Contributions validées** : un tutoriel validé rapporte des pelles en or, montant choisi par l'admin à la validation (barème par défaut) |
| 41.6 | **Quêtes hebdomadaires** : objectifs de la semaine (atteindre un goal, jouer avec quelqu'un de nouveau, participer à 3 hebdos d'affilée), plafond hebdomadaire ; c'est la réponse au farm, plutôt que de payer chaque check |
| 41.7 | **Boutique de cosmétiques** : cadres, bannières, déblocages permanents, objets **saisonniers** disponibles pendant une fenêtre (ArchiLAN) puis plus jamais |
| plus tard | Prédictions sur un event, récompenses créées par les organisateurs, kudos entre joueurs, cagnotte communautaire, dépenses d'identité, jetons physiques à QR code, remerciement des dons (voir « Idées en réserve ») |

## Points durs identifiés à la revue

- **Hints contre pelles (41.3)** : le mode gratuit est réservé aux admins par conception (story 9.31). Le nouveau
  chemin doit être le seul accès joueur au mode gratuit, débit inclus, sur ses seuls slots. « Le hint échoue »
  doit être défini précisément : bridge injoignable ou en erreur, session arrêtée, ou aucun hint créé (lieu déjà
  vérifié, objet déjà trouvé ou déjà indiqué). Le remboursement est automatique dans tous ces cas.
- **Primes (41.4)** :
  - **qui est payé** : un slot a un propriétaire et des co-joueurs, et Archipelago ne dit pas lequel a fait le
    check (même constat qu'en story 30.45). Partage égal entre les joueurs du slot, ou propriétaire seul : à
    trancher ;
  - **ce qui compte** : un item reçu par `!release` ou `!collect` n'a été « trouvé » par personne. Il ne doit pas
    payer de prime (sinon abandonner une partie rapporte). Il faut distinguer ces envois dans le fil, ou les
    exclure par le marquage des slots releasés ;
  - **le slot observateur `Bridge`** et le joueur lui-même (qui trouverait son propre item) ne touchent rien ;
  - **un item en plusieurs exemplaires** : la prime porte sur le prochain exemplaire reçu ;
  - **fiabilité** : un événement manqué par le bridge (redémarrage) ne doit pas laisser une prime bloquée. Une
    prime non versée est remboursée à la fin de la partie.
- **Pelles d'event (41.2)** : un événement est un `Event` (contexte `Events`). Les hebdos (`WeeklyRuns`) et les
  parties privées n'en sont pas : les pelles d'event ne s'y dépensent pas. La « fin » d'un event est sa date de
  fin, et l'expiration est un mouvement du registre.
- **Comptes multiples (41.6)** : une quête comme « jouer avec quelqu'un de nouveau » se triche avec un second
  compte. Les quêtes ne doivent compter que des parties réellement jouées (checks, goal), et un plafond
  hebdomadaire borne le reste.

## Décisions à prendre

1. **Prix d'un hint** : fixe sur tout le site, ou réglé par partie ? Le même pour un hint d'objet et de lieu ?
   (bloque 41.3) **Tranché (Jean, 2026-10-03)** : un prix par défaut sur le site, réglable par partie ; un prix pour
   un hint d'objet, un autre pour un hint de lieu.
2. **Hints contre pelles par défaut** : autorisés ou non ? Proposition : désactivés dans les parties privées,
   réglés par l'admin pour les hebdos et les events. (bloque 41.3) **Tranché (Jean, 2026-10-03)** : la proposition.
3. **Fin d'un event** : les pelles d'event disparaissent, ou une part (par exemple 10 %) devient des pelles en or
   en récompense ? (bloque 41.2) **Tranché (Jean, 2026-10-03)** : une part (10 %) passe en or, le reste est détruit.
4. **Barème** des quêtes et des contributions, plafond hebdomadaire. (bloque 41.5 et 41.6)
5. **Cosmétiques** : lesquels deviennent achetables, et ce qui reste gratuit pour les adhérents (les avantages
   actuels des adhérents et des admins, stories 30.40 à 30.46, ne doivent pas perdre leur valeur). (bloque 41.7)
6. **Démarrage** : bonus de lancement calculé sur l'historique de chacun, ou tout le monde part de zéro ?
   (bloque la mise en production de 41.1)
7. **Primes** : la prime d'un slot joué à plusieurs va au propriétaire seul, ou se partage entre ses joueurs ?
   (bloque 41.4) **Tranché (Jean, 2026-10-03)** : au propriétaire seul.

## Idées en réserve

Reprises de ce que font les autres, à reprendre selon ce que demande la communauté :

- **Prédictions d'event** (Twitch) : parier des pelles sur « qui finit son goal en premier », les gagnants se
  partagent la cagnotte. Condition ferme : pelles jamais achetables ni convertibles, sinon on se rapproche des
  jeux d'argent ; à faire valider par le trésorier.
- **Récompenses créées par les organisateurs** (Twitch) : le propriétaire d'une partie ou l'organisateur d'un
  event crée ses récompenses (« tu choisis le prochain jeu de l'hebdo »), avec prix, limite par personne, délai,
  validation par l'organisateur.
- **Kudos entre joueurs** (récompenses Steam) : saluer un récap, un tutoriel, un commentaire ; on dépense 10, la
  personne reçoit 3, le reste est détruit ; plafond par jour.
- **Cagnotte communautaire** : verser des pelles vers un objectif commun (une bannière pour tous, un jeu au
  catalogue, le thème de la prochaine ArchiLAN).
- **Dépenses d'identité** : titre personnalisé sous le pseudo pour une durée limitée, profil mis en avant,
  changement d'URL de profil ; prix ou durées proportionnels pour rester attractifs.
- **Jetons physiques** en LAN (bois gravé, impression 3D, puis métal), avec un QR code à usage unique scanné
  sur le site.
- **Remerciement des dons** : montant fixe et symbolique quel que soit le don, plafonné, avec un badge
  « Soutien » ; à faire valider par le trésorier.

## Sources de la réflexion

- Twitch, points de chaîne et prédictions : récompenses définies par chaque streamer (prix, délai, limite par
  personne), paris à cagnotte partagée.
- Steam, Boutique de points : cosmétiques de profil, objets saisonniers introuvables après l'événement,
  récompenses offertes dont le destinataire touche un tiers.
- Reddit, Coins et Awards supprimés en septembre 2023 : trop de sortes de récompenses.
- Conception des économies de jeu : équilibrer sources et puits, puits proportionnels à la richesse.
