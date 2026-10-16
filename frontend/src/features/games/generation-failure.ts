/**
 * Story 38.16: what a failed Archipelago generation means, for the person reading it.
 *
 * The API's GenerationFailureParser already reduces a crash to one message per finding (a French sentence for a
 * required file, `OptionError: ...`, `Fill.FillError: ...`, `preflight timed out ...`) and stores it as text. This is
 * the one place that sorts such a message into a kind a person can act on; every screen shows that kind the same way.
 */

export type GenerationFailureKind = "required_file" | "missing_file" | "invalid_yaml" | "invalid_option" | "fill" | "timeout" | "unknown";

export type GenerationFailureAudience = "player" | "admin";

const PATTERNS: [GenerationFailureKind, RegExp][] = [
  // A file the world declares in host.yaml (story 44.1): the launch asks for it.
  ["required_file", /demande le fichier|does not exist, but .+ is required/i],
  // A file the generation does not find, the world not declaring it: a file to provide, or a path the apworld assumes
  // (Beat Saber wrote its song cache to /Archipelago/data, story 38.17). The message alone cannot tell which.
  ["missing_file", /filenotfounderror|no such file or directory/i],
  ["timeout", /timed out|délai dépassé/i],
  ["fill", /fillerror|no more spots to place|could not access required locations|not all locations/i],
  ["invalid_option", /optionerror|is not a valid option|not a valid option|invalid value for/i],
  ["invalid_yaml", /fichier yaml invalide|yaml\.\w*error|scannererror|parsererror|constructorerror|\.yaml document #/i],
];

/** The kind of a failure message; anything unrecognised is `unknown`, shown with its own words. */
export function classifyGenerationFailure(message: string): GenerationFailureKind {
  for (const [kind, pattern] of PATTERNS) {
    if (pattern.test(message)) return kind;
  }
  return "unknown";
}

type Copy = { title: string; player: string; admin: string };

const COPY: Record<GenerationFailureKind, Copy> = {
  required_file: {
    title: "Fichier requis manquant",
    player: "Le jeu lit un fichier pendant la génération, une ROM le plus souvent : le lanceur de la partie doit le fournir au lancement.",
    admin: "Fournis le fichier au test, ou retire l'exigence dans « Fichiers du joueur » si elle a été posée à tort.",
  },
  missing_file: {
    title: "Fichier manquant",
    player: "Le jeu cherche un fichier qu'il ne trouve pas pendant la génération. Transmets le message à l'équipe : ça se règle de notre côté.",
    admin:
      "L'apworld cherche un fichier absent de l'environnement de génération : un fichier à fournir, ou un chemin qu'il suppose ailleurs (Beat Saber écrivait son cache dans /Archipelago/data). Le journal technique dit lequel.",
  },
  invalid_yaml: {
    title: "YAML illisible",
    player: "Le YAML du slot ne se lit pas (indentation, guillemets, valeur mal placée) : corrige-le ou repars du modèle du jeu.",
    admin: "Le YAML testé ne se lit pas : vérifie le template du jeu, ou le YAML collé.",
  },
  invalid_option: {
    title: "Option refusée",
    player: "Une option du slot a une valeur que le jeu n'accepte pas : change-la dans la configuration du slot.",
    admin: "Une valeur du template n'est pas acceptée par cette version de l'apworld : corrige le template, ou signale-le à son auteur.",
  },
  fill: {
    title: "Remplissage impossible",
    player:
      "Archipelago n'a pas réussi à placer tous les objets avec ces réglages : assouplis les options (accessibilité, objets exclus, nombre de checks) puis relance.",
    admin: "Aucune seed ne tient avec ces options : souvent un réglage trop restrictif du template, parfois un défaut de l'apworld.",
  },
  timeout: {
    title: "Génération trop longue",
    player: "La génération a dépassé le temps imparti : réessaie, ou réduis la taille de la partie.",
    admin: "Le test a dépassé le temps imparti : l'apworld est peut-être bloqué ou trop lent pour une seule seed.",
  },
  unknown: {
    title: "Erreur de génération",
    player: "Le message ci-dessous vient d'Archipelago. Si tu ne sais pas quoi en faire, transmets-le à l'équipe.",
    admin: "Le message vient d'Archipelago : lis le journal technique, et signale l'erreur à l'auteur de l'apworld si elle vient de lui.",
  },
};

/** The title and the advice of a kind, worded for its reader. */
export function generationFailureCopy(kind: GenerationFailureKind, audience: GenerationFailureAudience): { title: string; advice: string } {
  const copy = COPY[kind];
  return { title: copy.title, advice: copy[audience] };
}

/** The prefix SessionLifecycleManager puts before a slot's finding: the notice says the slot on its own. */
export function withoutSlotPrefix(message: string): string {
  return message.replace(/^La génération a échoué à cause de ce slot : /, "");
}
