import { EVENT_GOAL_PREFIX, EVENTS_FACT, factLabel } from "./admin-achievement-event-scope";
import { conditionPhrase } from "@/features/community/achievement-phrasing";

import { isRuleGroup, type AchievementFormOptions, type RuleCondition, type RuleGroup, type RuleGroupOp, type RuleNode, type RuleOperator } from "./admin-achievements-api";

/**
 * Story 30.51: how the admin reads and reshapes an achievement rule - the family of each criterion (its colour and
 * icon), the short chip text, the rule in plain French, and moving a condition or a group inside the tree.
 */

export type FactFamily = "parties" | "progression" | "objectifs" | "quetes" | "recaps" | "autres";

export const FAMILY_LABELS: Record<FactFamily, string> = {
  parties: "Parties",
  progression: "Progression",
  objectifs: "Objectifs et événements",
  quetes: "Quêtes",
  recaps: "Récaps",
  autres: "Autres",
};

export const FAMILY_ORDER: readonly FactFamily[] = ["parties", "progression", "objectifs", "quetes", "recaps", "autres"];

const FAMILY_OF_FACT: Record<string, FactFamily> = {
  runs: "parties",
  distinctGames: "parties",
  checks: "progression",
  items: "progression",
  itemsFromOthers: "progression",
  goals: "objectifs",
  [EVENTS_FACT]: "objectifs",
  questsCompleted: "quetes",
  questChestStreak: "quetes",
  superlatives: "recaps",
};

export function familyOf(fact: string): FactFamily {
  if (fact.startsWith(EVENT_GOAL_PREFIX)) return "objectifs";
  if (fact.startsWith("superlative:")) return "recaps";
  return FAMILY_OF_FACT[fact] ?? "autres";
}

/** The facts the picker offers, grouped by family in a stable order. */
export function factsByFamily(options: AchievementFormOptions): { family: FactFamily; facts: { key: string; label: string }[] }[] {
  return FAMILY_ORDER.map((family) => ({ family, facts: options.facts.filter((f) => familyOf(f.key) === family) })).filter((g) => g.facts.length > 0);
}

/** The criterion as a chip says it: its label without the parenthesised detail. */
export function shortFactLabel(fact: string, options: AchievementFormOptions): string {
  return factLabel(fact, options).replace(/\s*\([^)]*\)/g, "").trim();
}

const NUMBER = new Intl.NumberFormat("fr-FR");

const OPERATOR_SYMBOLS: Record<RuleOperator, string> = { ">=": "≥", ">": ">", "=": "=", "!=": "≠", "<=": "≤", "<": "<", between: "entre" };

export const OPERATOR_WORDS: Record<RuleOperator, string> = {
  ">=": "au moins",
  ">": "plus de",
  "=": "exactement",
  "!=": "autre que",
  "<=": "au plus",
  "<": "moins de",
  between: "entre",
};

/** « ≥ 1 000 », « entre 500 et 2 000 ». */
export function conditionValue(condition: RuleCondition): string {
  if (condition.operator === "between") {
    return `entre ${NUMBER.format(condition.value)} et ${NUMBER.format(condition.value2 ?? condition.value)}`;
  }
  return `${OPERATOR_SYMBOLS[condition.operator]} ${NUMBER.format(condition.value)}`;
}

export const GROUP_JOINERS: Record<RuleGroupOp, string> = { all: "ET", any: "OU", none: "NI" };

/** What a sub-group's frame says: « tous », « un de », « aucun de ». */
export const GROUP_TAGS: Record<RuleGroupOp, string> = { all: "tous", any: "un de", none: "aucun de" };

export function ruleDepth(node: RuleNode): number {
  if (!isRuleGroup(node)) return 0;
  return 1 + Math.max(0, ...node.rules.map(ruleDepth));
}

/** « jouer 10 parties » - a sentence's first letter down, to sit inside the « En clair » line. */
function lower(phrase: string): string {
  return `${phrase.charAt(0).toLowerCase()}${phrase.slice(1)}`;
}

/** Story 30.54: a condition as the member would say it, the sentence the profile's modal shows. */
function conditionInFrench(condition: RuleCondition, options: AchievementFormOptions, negated: boolean): string {
  const eventId = condition.fact.startsWith(EVENT_GOAL_PREFIX) ? condition.fact.slice(EVENT_GOAL_PREFIX.length) : null;
  const event = eventId === null ? undefined : options.events.find((e) => e.id === eventId);
  const label = eventId === null ? factLabel(condition.fact, options) : `Objectif atteint à « ${event ? event.title : "événement supprimé"} »`;
  return lower(conditionPhrase({ fact: condition.fact, label, operator: condition.operator, value: condition.value, value2: condition.value2 ?? null }, negated));
}

function groupInFrench(group: RuleGroup, options: AchievementFormOptions, nested: boolean): string {
  const negated = group.op === "none";
  const parts = group.rules.map((node) =>
    isRuleGroup(node) ? `${negated ? "ne pas " : ""}${groupInFrench(node, options, true)}` : conditionInFrench(node, options, negated),
  );
  if (parts.length === 0) return "(groupe vide)";
  const body = parts.join(group.op === "any" ? ", ou " : ", et ");
  return nested && parts.length > 1 ? `(${body})` : body;
}

/** The whole rule as one French sentence, the guard against an ET put where an OU was meant. */
export function ruleInFrench(rule: RuleGroup, options: AchievementFormOptions): string {
  return `Pour le débloquer : ${groupInFrench(rule, options, false)}.`;
}

// ── Moving inside the tree ───────────────────────────────────────────────────

/** A node's place: the indexes from the root group down to it. */
export type RulePath = number[];

export function pathKey(path: RulePath): string {
  return path.join(".");
}

export function parsePathKey(key: string): RulePath {
  return key === "" ? [] : key.split(".").map(Number);
}

function groupAt(root: RuleGroup, path: RulePath): RuleGroup | null {
  let node: RuleNode = root;
  for (const index of path) {
    if (!isRuleGroup(node)) return null;
    const child: RuleNode | undefined = node.rules[index];
    if (child === undefined) return null;
    node = child;
  }
  return isRuleGroup(node) ? node : null;
}

function updateGroup(root: RuleGroup, path: RulePath, change: (group: RuleGroup) => RuleGroup): RuleGroup {
  if (path.length === 0) return change(root);
  const [head, ...rest] = path;
  return {
    ...root,
    rules: root.rules.map((node, index) => (index === head && isRuleGroup(node) ? updateGroup(node, rest, change) : node)),
  };
}

function isPrefix(prefix: RulePath, path: RulePath): boolean {
  return prefix.length <= path.length && prefix.every((value, index) => path[index] === value);
}

/**
 * Moves the node at `from` to position `index` of the group at `toGroup`. A group never goes inside itself; a move
 * that changes nothing, or points nowhere, returns the rule unchanged.
 */
export function moveNode(root: RuleGroup, from: RulePath, toGroup: RulePath, index: number): RuleGroup {
  if (from.length === 0 || isPrefix(from, toGroup)) return root;
  const fromParent = from.slice(0, -1);
  const fromIndex = from[from.length - 1] ?? 0;
  const source = groupAt(root, fromParent);
  const node = source?.rules[fromIndex];
  if (node === undefined || groupAt(root, toGroup) === null) return root;

  // The destination, seen after the removal: same group further down shifts by one, and a group after the moved
  // node in a shared ancestor moves up by one.
  const target = [...toGroup];
  const depth = fromParent.length;
  let at = index;
  if (pathKey(fromParent) === pathKey(toGroup)) {
    if (fromIndex < index) at -= 1;
    if (fromIndex === at) return root;
  } else if (isPrefix(fromParent, toGroup) && (target[depth] ?? 0) > fromIndex) {
    target[depth] = (target[depth] ?? 0) - 1;
  }

  const removed = updateGroup(root, fromParent, (group) => ({ ...group, rules: group.rules.filter((_, i) => i !== fromIndex) }));
  return updateGroup(removed, target, (group) => {
    const rules = [...group.rules];
    rules.splice(Math.min(Math.max(at, 0), rules.length), 0, node);
    return { ...group, rules };
  });
}
