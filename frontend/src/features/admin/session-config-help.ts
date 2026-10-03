/**
 * Single source of truth for the contextual help shown next to each session-config option
 * (via the ⓘ InfoTooltip), reused by the per-type profile editor and the override form.
 *
 * The text describes what the option does; the per-value meaning lives in the value labels
 * (e.g. "Après l'objectif atteint"). Kept to 1–2 short sentences so the popover stays light.
 */
export const sessionConfigHelp: Record<string, string> = {
  releaseMode:
    "Commande !release : un joueur ayant atteint son objectif peut envoyer tous ses objets encore non trouvés vers les autres mondes.",
  collectMode:
    "Commande !collect : un joueur ayant fini récupère d'un coup ses propres objets encore présents dans les mondes des autres.",
  remainingMode:
    "Commande !remaining : permet à un joueur de demander la liste des objets qu'il doit encore recevoir.",
  pelleHints:
    "Permet aux joueurs d'acheter un indice avec leurs pelles (pelles de l'événement d'abord, puis pelles en or). Sans effet sur les points d'indice Archipelago.",
  pelleBounties:
    "Permet aux joueurs d'offrir des pelles en or à qui leur enverra un objet précis ; 10 % de la prime sont détruits au versement.",
  pelleItemHintPrice: "Prix en pelles d'un indice sur un objet (où se trouve tel objet).",
  pelleLocationHintPrice: "Prix en pelles d'un indice sur un lieu (ce que cache tel check).",
  hintCost:
    "Prix d'un indice, en pourcentage du nombre total de checks. Les joueurs gagnent des points d'indice en validant des checks.",
  locationCheckPoints:
    "Nombre de points d'indice gagnés à chaque check trouvé. Plus c'est élevé, plus les indices sont accessibles.",
  countdownMode:
    "Commande !countdown : autorise le lancement d'un compte à rebours partagé dans la salle (départ synchronisé).",
  disableItemCheat:
    "Désactive la commande !getitem qui permet de se faire envoyer n'importe quel objet. À activer pour une partie « propre » sans triche.",
  compatibility:
    "Tolérance vis-à-vis des clients qui se connectent. Casual = permissif ; Tournoi = strict (clients officiels à jour uniquement).",
  autoShutdown:
    "Arrêt automatique du serveur après ce délai (en secondes) sans nouveau check. 0 = ne s'arrête jamais. C'est le mécanisme de mise en pause des parties (relance manuelle ensuite).",
  joinPassword:
    "Mot de passe demandé aux joueurs pour rejoindre la salle. Laisser vide pour une partie ouverte.",
  plandoOptions:
    "Autorise les fonctions « plando » à la génération (placement manuel d'objets, de boss, de textes ou de connexions). Sélectionne les catégories permises.",
  race:
    "Mode course : la sortie générée est chiffrée pour empêcher de fouiller le seed à l'avance. À utiliser pour les compétitions.",
  spoiler:
    "Niveau du fichier spoiler généré : d'aucun (course équitable) à complet avec les chemins logiques.",
};
