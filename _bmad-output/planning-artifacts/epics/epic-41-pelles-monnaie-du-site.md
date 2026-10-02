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

## Existant réutilisé

- **Hint gratuit en partie** : le bridge sait déjà donner un hint d'objet ou de lieu sans toucher aux points de
  hint d'Archipelago (`rest_hints.py`, `free=true`, stories 9.28 à 9.30) ; l'API le propose aux admins
  (`PlayerStateController`). Acheter un hint = débiter des pelles, puis demander ce hint gratuit.
- **Config de partie** (epic 27, `SessionServerConfig`) : un réglage « hints contre pelles » s'y ajoute avec les
  mêmes règles de qui le règle (admin pour les hebdos et les events, propriétaire pour les parties privées).
- **Qui a trouvé quoi** : le fil d'événements des récaps (epic 32) connaît l'envoi de chaque item, ce qui permet
  de payer une prime à celui qui l'a trouvé.
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
| 41.4 | **Primes sur les items** : un joueur bloqué offre des pelles pour un item ; celui qui l'envoie la reçoit automatiquement ; petite commission détruite ; prime remboursée si la partie se termine sans l'item |
| 41.5 | **Contributions validées** : un tutoriel validé rapporte des pelles en or, montant choisi par l'admin à la validation (barème par défaut) |
| 41.6 | **Quêtes hebdomadaires** : objectifs de la semaine (atteindre un goal, jouer avec quelqu'un de nouveau, participer à 3 hebdos d'affilée), plafond hebdomadaire ; c'est la réponse au farm, plutôt que de payer chaque check |
| 41.7 | **Boutique de cosmétiques** : cadres, bannières, déblocages permanents, objets **saisonniers** disponibles pendant une fenêtre (ArchiLAN) puis plus jamais |
| plus tard | Prédictions sur un event, récompenses créées par les organisateurs, kudos entre joueurs, cagnotte communautaire, dépenses d'identité, jetons physiques à QR code, remerciement des dons (voir « Idées en réserve ») |

## Décisions à prendre

1. **Prix d'un hint** : fixe sur tout le site, ou réglé par partie ? Le même pour un hint d'objet et de lieu ?
   (bloque 41.3)
2. **Hints contre pelles par défaut** : autorisés ou non ? Proposition : désactivés dans les parties privées,
   réglés par l'admin pour les hebdos et les events. (bloque 41.3)
3. **Fin d'un event** : les pelles d'event disparaissent, ou une part (par exemple 10 %) devient des pelles en or
   en récompense ? (bloque 41.2)
4. **Barème** des quêtes et des contributions, plafond hebdomadaire. (bloque 41.5 et 41.6)
5. **Cosmétiques** : lesquels deviennent achetables, et ce qui reste gratuit pour les adhérents (les avantages
   actuels des adhérents et des admins, stories 30.40 à 30.46, ne doivent pas perdre leur valeur). (bloque 41.7)
6. **Démarrage** : bonus de lancement calculé sur l'historique de chacun, ou tout le monde part de zéro ?
   (bloque la mise en production de 41.1)

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
