# Story 44.8 - Correctifs de la revue de l'epic 44

**Status:** review
**Epic:** 44 - Fichiers requis à la génération (ROM fournie par le joueur)
**Date:** 2026-10-16
**Origine :** revue de code de l'epic 44 demandée par Jean le 2026-10-16 (PR #849, #852, #855, orchestrateur #33),
« corrige la totale ». Branche empilée sur la 38.17 (#856) ; correctifs orchestrateur sur la #33 directement.

## Correctifs

### Orchestrateur (#33, commit 4b28fc9)
1. Une ROM consommée reste comptée dans le quota jusqu'à la fin de son test ou de sa génération : des tests en
   attente d'un conteneur ne peuvent plus accumuler des fichiers en mémoire au-delà du plafond.
2. Le dernier test lancé possède le verdict (`RunID`) : un test plus ancien qui finit après n'écrit rien ; un test
   sans fichier ne démarre pas pendant qu'un test avec fichier est en cours (`PendingWithFiles`).
3. Un test qui ne peut pas aboutir (téléchargement, stockage) remet le verdict précédent au lieu de le laisser
   « en cours ».
4. Le même identifiant envoyé deux fois répond 400 (et non « renvoie le fichier ») ; corps des routes de test bornés.

### API
5. `RunSlotPreflightJob::__unserialize` : un message mis en file avant `requiredFileIds` se décode (aucun fichier) au
   lieu de faire planter le worker.
6. Test de slot : fichiers rendus quand le joueur a quitté la partie, ou si l'enregistrement / la mise en file
   échoue ; un suivi qui ne peut être mis en file est enregistré comme échec (« Le suivi du test a été perdu »)
   au lieu d'être retenté avec des fichiers déjà consommés ; message distinct quand un test avec fichier ne démarre
   pas (runner ou fichier expiré).
7. Un test de slot « en cours » depuis plus de 15 minutes s'affiche comme échoué (« Le test n'a pas répondu :
   relance-le. ») : le bouton revient.
8. `Game::needsAFileAtGeneration()` ; pour un tel jeu, pas de test relancé sans le fichier : à l'enregistrement ou à
   la régénération du template (avertissement « relance le test avec lui »), à la mise en service d'une nouvelle
   version (slots non testés), ni par `backfill --all` (verdict réussi avec fichier laissé tel quel).

### Front
9. MD5 comparé sans tenir compte de la casse.
10. Seuls les fichiers encore nécessaires sont envoyés.
11. Page de session admin : relue quand une génération échoue (un fichier peut venir d'y être exigé).
12. Modales de test : « Retirer le fichier » quand le fichier choisi est refusé.
13. Test YAML d'une nouvelle version : propose aussi les fichiers seulement déclarés (la nouvelle version peut en
    exiger un que l'ancienne n'exigeait pas).

### Documentation
14. Story 44.5 : AC6 amendé (écart accepté, reprise depuis la sauvegarde sans régénération).

## Non repris

- Vérifications réelles des 44.5 et 44.6 (AC13 / AC14) : à faire avec la vraie ROM DKC3, orchestrateur à jour.

## Tests

- Go : quota tenu jusqu'à la fin du run, doublon refusé, propriété du verdict, corps borné.
- API : décodage d'un ancien message, joueur parti, suivi perdu, test en attente trop long, template d'un jeu à ROM,
  mise en service sans test, backfill, test YAML sans runner qui rend le fichier.
- Front : MD5 en majuscules, fichiers encore nécessaires, fichier à retirer.
