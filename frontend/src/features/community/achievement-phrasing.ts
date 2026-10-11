import type { ProgressCondition } from "./achievement-progress-api";

const NUMBER = new Intl.NumberFormat("fr-FR");

type Condition = Pick<ProgressCondition, "fact" | "label" | "operator" | "value" | "value2">;

/** How a criterion reads as a sentence: a verb, then the count with its noun (singular, plural). */
type Phrasing = { verb: string; a: "un" | "une"; one: string; many: string; after?: string };

const PHRASINGS: Record<string, Phrasing> = {
  runs: { verb: "Jouer", a: "une", one: "partie", many: "parties" },
  distinctGames: { verb: "Jouer à", a: "un", one: "jeu différent", many: "jeux différents" },
  checks: { verb: "Compléter", a: "un", one: "check", many: "checks" },
  items: { verb: "Recevoir", a: "un", one: "item", many: "items" },
  itemsFromOthers: { verb: "Recevoir", a: "un", one: "item", many: "items", after: "d'autres joueurs (hors release et collect)" },
  goals: { verb: "Atteindre", a: "un", one: "objectif", many: "objectifs" },
  eventsWithGoal: { verb: "Atteindre son objectif dans", a: "un", one: "événement", many: "événements" },
  questsCompleted: { verb: "Réussir", a: "une", one: "quête de la semaine", many: "quêtes de la semaine" },
  questChestStreak: { verb: "Ouvrir le coffre des quêtes", a: "une", one: "semaine d'affilée", many: "semaines d'affilée" },
  superlatives: { verb: "Remporter", a: "un", one: "superlatif de récap", many: "superlatifs de récap" },
  // Story 43.16: playing with others.
  distinctCoplayers: { verb: "Jouer avec", a: "un", one: "joueur différent", many: "joueurs différents" },
  distinctFriendsPlayedWith: { verb: "Jouer avec", a: "un", one: "ami différent", many: "amis différents" },
  maxFinishedWithSamePerson: { verb: "Terminer", a: "une", one: "partie", many: "parties", after: "avec la même personne" },
  weeklyDuelsWon: { verb: "Gagner", a: "un", one: "duel hebdo", many: "duels hebdo" },
};

/** « 3 000 », « plus de 10 », « entre 500 et 2 000 »: the count a condition asks for. */
function quantity(condition: Condition): string {
  const n = NUMBER.format(condition.value);
  switch (condition.operator) {
    case ">=":
      return n;
    case ">":
      return `plus de ${n}`;
    case "=":
      return `exactement ${n}`;
    case "!=":
      return `autre chose que ${n}`;
    case "<=":
      return `au plus ${n}`;
    case "<":
      return `moins de ${n}`;
    case "between":
      return `entre ${n} et ${NUMBER.format(condition.value2 ?? condition.value)}`;
    default:
      return n;
  }
}

/** Plural from the upper count the condition can mean: « 1 partie », « 2 parties », « entre 1 et 3 parties ». */
function noun(condition: Condition, phrasing: Phrasing): string {
  const upper = condition.operator === "between" ? (condition.value2 ?? condition.value) : condition.value;
  return upper > 1 || condition.operator === ">" ? phrasing.many : phrasing.one;
}

/** « Le Parrain » from « Superlatif « Le Parrain » (le plus généreux) », « ArchiLAN #3 » from « Objectif atteint à « ArchiLAN #3 » ». */
function quoted(label: string): string | null {
  return label.match(/« (.+?) »/)?.[1] ?? null;
}

/** A count of 1 at least, the most common threshold, reads without it: « Remporter le superlatif « Le Parrain » ». */
function once(condition: Condition): boolean {
  return condition.operator === ">=" && condition.value === 1;
}

function negate(phrase: string): string {
  return `Ne pas ${phrase.charAt(0).toLowerCase()}${phrase.slice(1)}`;
}

/**
 * Story 30.53: a condition as the member would say it - « Recevoir 3 000 items d'autres joueurs », « Atteindre l'objectif à
 * « ArchiLAN #3 » », « Remporter le superlatif « Le Parrain » 2 fois ». Under « aucune », it reads « Ne pas … ». A
 * criterion this table does not know falls back to its label and its target.
 */
export function conditionPhrase(condition: Condition, negated = false): string {
  return negated ? negate(phrase(condition)) : phrase(condition);
}

function phrase(condition: Condition): string {
  const count = quantity(condition);
  if (condition.fact.startsWith("event_goal:")) {
    const event = quoted(condition.label) ?? "un événement";
    return once(condition) ? `Atteindre son objectif à « ${event} »` : `Atteindre son objectif à « ${event} » (${count})`;
  }
  if (condition.fact.startsWith("superlative:")) {
    const title = quoted(condition.label) ?? condition.label;
    if (once(condition)) return `Remporter le superlatif « ${title} »`;
    return `Remporter le superlatif « ${title} » ${count} fois`;
  }
  const phrasing = PHRASINGS[condition.fact];
  if (phrasing === undefined) return `${condition.label} : ${count}`;
  const what = once(condition) ? `${phrasing.a} ${phrasing.one}` : `${count} ${noun(condition, phrasing)}`;
  return [phrasing.verb, what, phrasing.after].filter((part) => part !== undefined).join(" ");
}
