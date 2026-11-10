# Epic 51: Sondages

**Statut :** proposé le 2026-11-09
**Date :** 2026-11-09
**Origine :** échange avec Jean le 2026-11-09, à partir d'un besoin concret de l'ArchiLAN 4 (savoir quand les
inscrits viennent, alors que les inscriptions sont déjà ouvertes).

## Constat

L'orga n'a aucun moyen de poser une question aux inscrits d'un événement depuis le site. Les questions passent
par Discord ou en message privé, beaucoup restent sans réponse, et rien ne dit qui n'a pas répondu. Le besoin
existe à trois moments :

- **à l'inscription** (« Tu dors sur place ? ») ;
- **avant l'événement**, inscriptions déjà faites (« Quels jours viens-tu ? ») ;
- **après l'événement** (« Note l'organisation », « Qu'est-ce qu'on améliore ? »).

Rien n'existe aujourd'hui (aucune question personnalisée ni sondage dans `Registrations` ou `Events`).

## Cadrage de Jean

- **Générique, rempli par l'orga** : l'admin écrit ses propres questions, pas de formulaire codé en dur. « Quand
  viens-tu ? » est une question comme une autre.
- **Avant et après** : un même outil de sondage, dont seuls le public et la période changent.
- **Un mail** prévient le public concerné : l'encart sur le site ne touche que ceux qui reviennent.

## Modèle

- Un **sondage** est rattaché à un événement. Il a un titre, un texte d'introduction, un **public**, une
  **période** (ouverture, fermeture), une option **anonyme** et une option **envoyer par mail**.
- **Public** (énumération fermée) :
  - `registration` : à l'inscription (étape du parcours d'inscription, et encart pour les inscrits d'avant la
    création du sondage) ;
  - `registrants` : les inscrits actifs de l'événement ;
  - `participants` : les inscrits qui ont joué (voir « Participant » ci-dessous).
- **Questions** ordonnées, de quatre types : choix unique, choix multiple, texte court, note de 1 à 5. Chaque
  question peut être obligatoire.
- **Options** d'une question à choix : identifiant stable, libellé. Une option qui a des réponses ne se renomme
  pas (sinon une réponse « samedi » deviendrait « dimanche ») ; elle peut être retirée (masquée, réponses
  conservées) et on peut en ajouter.
- **Deux états** : brouillon (modifiable librement, invisible des joueurs) et publié. À la publication, le public
  et l'option anonyme sont **figés**.

## Principes

- **Participation et contenu séparés.** Le fait d'avoir répondu (qui, quand) est toujours nominatif : c'est ce
  qui permet de relancer les non-répondants. Pour un sondage **anonyme**, le contenu des réponses est stocké sans
  aucun lien vers la personne (ni id, ni horodatage précis qui permettrait de recouper), et ne se modifie pas
  après envoi. Le formulaire le dit au joueur.
- **Réponses modifiables jusqu'à la fermeture** pour un sondage nominatif.
- **Visibles par l'orga seulement** : aucune route publique ni membre ne renvoie les réponses des autres.
- **Participant** = inscription active et confirmée (`reserved`, `submitted_at` non nul) qui a au moins un slot
  dans une session lancée de l'événement. Sans aucune session sur l'événement : tous les inscrits confirmés. La
  requête existante `DbalEventParticipationQuery` exige un goal atteint : ne pas la réutiliser telle quelle.
- **Diffusion après commit** : mail, notification et push partent après l'écriture, en best effort, sur le modèle
  des relances d'inscription (`ProcessUnconfirmedRegistrationsHandler`) et du `Notifier`.
- **Rien d'automatique sur l'événement** : un événement « terminé » est un passage manuel en `completed`. Le
  sondage d'après l'événement s'appuie donc sur sa propre date d'ouverture (proposée par défaut à la fin de
  l'événement), pas sur le statut.

## Stories

### 51.1 - Socle des sondages (API admin)

En tant qu'admin, je veux créer un sondage sur un événement (questions, public, période, anonyme, mail), le
modifier tant qu'il est en brouillon et le publier, afin de préparer mes questions.

- Nouveau contexte `Surveys`, CRUD admin, règles de modification des options, publication qui fige.
- Détail : `implementation-artifacts/51-1-socle-sondages.md`.

### 51.2 - Le joueur répond (API membre + front)

En tant que joueur concerné, je veux voir qu'un sondage m'attend sur la page de l'événement et y répondre, afin
que l'orga ait l'information.

- Éligibilité selon le public, enregistrement des réponses (nominatif modifiable, anonyme définitif), encart sur la
  page publique de l'événement, page de réponse.
- Détail : `implementation-artifacts/51-2-repondre-sondage.md`.

### 51.3 - Prévenir et relancer (mail, notification, push)

En tant qu'orga, je veux que le public soit prévenu à l'ouverture et que les non-répondants soient relancés avant
la fermeture, afin d'avoir des réponses sans courir après chacun.

- Mail + notification (+ push) à l'ouverture, relance unique avant la fermeture, bouton de relance manuelle.
- Détail : `implementation-artifacts/51-3-prevenir-relancer.md`.

### 51.4 - Résultats (admin)

En tant qu'orga, je veux voir les résultats d'un sondage et la liste de ceux qui n'ont pas répondu, afin d'agir.

- Décomptes, moyennes, réponses texte, vue par inscrit (nominatif seulement), non-répondants.
- Détail : `implementation-artifacts/51-4-resultats-sondage.md`.

### 51.5 - Questions à l'inscription

En tant que joueur qui s'inscrit, je veux répondre aux questions de l'orga pendant mon inscription, afin de ne
pas être relancé ensuite.

- Étape du parcours d'inscription pour les sondages de public `registration`, questions obligatoires bloquantes à
  la validation.
- Détail : `implementation-artifacts/51-5-questions-inscription.md`.

## Dépendances

- 51.1 avant tout le reste.
- 51.2 avant 51.3, 51.4 et 51.5.
- 51.3 et 51.4 indépendantes entre elles.
- **Pour l'ArchiLAN 4** (inscriptions en cours) : 51.1, 51.2, 51.3 et 51.4 suffisent, avec un sondage de public
  `registrants`. 51.5 peut attendre.

## Hors périmètre (pour plus tard)

- Sondages sans événement, pour toute la communauté (nouveau public « membres »).
- Questions conditionnelles, export CSV, modèles de sondage réutilisables, pièces jointes.
- Résultats partagés aux joueurs.
