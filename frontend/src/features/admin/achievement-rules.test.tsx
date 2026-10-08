import { renderToStaticMarkup } from "react-dom/server";

import type { AchievementDefinition, AchievementFormOptions, RuleCondition, RuleGroup } from "./admin-achievements-api";
import { RuleChips } from "./achievement-rule-chips";
import { conditionValue, factsByFamily, familyOf, moveNode, ruleDepth, ruleInFrench, shortFactLabel } from "./achievement-rules";
import { NO_FILTERS, filterAchievements, isFiltered } from "./admin-achievements-dashboard";

const options: AchievementFormOptions = {
  facts: [
    { key: "runs", label: "Parties jouées" },
    { key: "distinctGames", label: "Jeux différents joués" },
    { key: "checks", label: "Checks complétés (total)" },
    { key: "itemsFromOthers", label: "Items reçus d'autres joueurs (hors release et collect)" },
    { key: "goals", label: "Objectifs atteints" },
    { key: "eventsWithGoal", label: "Événements avec objectif atteint" },
    { key: "questsCompleted", label: "Quêtes hebdo réussies (total)" },
    { key: "superlative:most_generous", label: "Superlatif « Le Parrain » (le plus généreux)" },
  ],
  operators: [">=", ">", "=", "!=", "<=", "<", "between"],
  groupOps: ["all", "any", "none"],
  events: [{ id: "lan3", title: "ArchiLAN #3" }],
};

const cond = (fact: string, value: number, operator: RuleCondition["operator"] = ">="): RuleCondition => ({ fact, operator, value });

/** Parties ≥ 10 ET (Objectif à ArchiLAN #3 OU (Checks entre 500 et 2 000 ET Quêtes ≥ 20)) ET aucun de (« Le Parrain » ≥ 1). */
const threeLevels: RuleGroup = {
  op: "all",
  rules: [
    cond("runs", 10),
    { op: "any", rules: [cond("event_goal:lan3", 1), { op: "all", rules: [{ fact: "checks", operator: "between", value: 500, value2: 2000 }, cond("questsCompleted", 20)] }] },
    { op: "none", rules: [cond("superlative:most_generous", 1)] },
  ],
};

/** Story 30.51: reading and reshaping an achievement rule. */
describe("achievement rules", () => {
  test("each criterion belongs to a family, the picker groups them in order", () => {
    expect(familyOf("runs")).toBe("parties");
    expect(familyOf("itemsFromOthers")).toBe("progression");
    expect(familyOf("event_goal:lan3")).toBe("objectifs");
    expect(familyOf("superlative:most_generous")).toBe("recaps");
    expect(familyOf("unknown")).toBe("autres");
    expect(factsByFamily(options).map((g) => g.family)).toEqual(["parties", "progression", "objectifs", "quetes", "recaps"]);
  });

  test("a chip drops the parenthesised detail and reads its value", () => {
    expect(shortFactLabel("itemsFromOthers", options)).toBe("Items reçus d'autres joueurs");
    expect(conditionValue(cond("checks", 1000))).toMatch(/^≥ 1\s000$/);
    expect(conditionValue({ fact: "checks", operator: "between", value: 500, value2: 2000 })).toMatch(/^entre 500 et 2\s000$/);
  });

  test("the whole rule reads as one French sentence", () => {
    expect(ruleInFrench(threeLevels, options)).toBe(
      "Débloqué si Parties jouées : au moins 10, et (Objectif - ArchiLAN #3 : au moins 1, ou (Checks complétés (total) entre 500 et 2 000, et Quêtes hebdo réussies (total) : au moins 20)), et aucun de (Superlatif « Le Parrain » (le plus généreux) : au moins 1).",
    );
    expect(ruleDepth(threeLevels)).toBe(3);
  });

  test("the list draws sub-groups as labelled frames, and gives up past three levels", () => {
    const html = renderToStaticMarkup(<RuleChips options={options} rule={threeLevels} />);
    expect(html).toContain(">un de<");
    expect(html).toContain(">aucun de<");
    expect(html).toContain(">ET<");

    const deep: RuleGroup = { op: "all", rules: [threeLevels] };
    expect(renderToStaticMarkup(<RuleChips options={options} rule={deep} />)).toContain("Règle à 4 niveaux");
  });
});

describe("moving inside a rule", () => {
  const flat: RuleGroup = { op: "all", rules: [cond("runs", 1), cond("goals", 2), cond("checks", 3)] };
  const facts = (group: RuleGroup) => group.rules.map((r) => ("fact" in r ? r.fact : `[${r.op}]`));

  test("within a group, down and up", () => {
    expect(facts(moveNode(flat, [0], [], 3))).toEqual(["goals", "checks", "runs"]);
    expect(facts(moveNode(flat, [2], [], 0))).toEqual(["checks", "runs", "goals"]);
    expect(moveNode(flat, [1], [], 1)).toBe(flat);
    expect(moveNode(flat, [1], [], 2)).toBe(flat);
  });

  test("into a sub-group and back out", () => {
    const into = moveNode(threeLevels, [0], [1, 1], 0);
    const sub = into.rules[0];
    expect(sub && "op" in sub ? sub.op : null).toBe("any");
    const nested = sub && "op" in sub ? sub.rules[1] : undefined;
    expect(nested && "op" in nested ? nested.rules.map((r) => ("fact" in r ? r.fact : "")) : []).toEqual(["runs", "checks", "questsCompleted"]);

    const out = moveNode(threeLevels, [1, 0], [], 0);
    expect(facts(out)).toEqual(["event_goal:lan3", "runs", "[any]", "[none]"]);
  });

  test("a group never goes inside itself", () => {
    expect(moveNode(threeLevels, [1], [1, 1], 0)).toBe(threeLevels);
  });
});

describe("filtering the catalogue", () => {
  const def = (key: string, name: string, rule: RuleGroup, active = true, reward = false): AchievementDefinition => ({
    id: key, key, name, description: "", rule, active, position: 0, customImageKey: null, customImageUrl: null,
    reward: reward ? { type: "frame", key: "envy", label: "Cadre « Mains de l'Envie »" } : null,
  });
  const all = [
    def("first_goal", "Premier objectif", { op: "all", rules: [cond("goals", 1)] }),
    def("regular", "Habitué", { op: "all", rules: [cond("runs", 10)] }, false),
    def("witch_of_envy", "Je t'aime", { op: "all", rules: [cond("itemsFromOthers", 1000)] }, true, true),
  ];

  test("search ignores case and accents, filters combine", () => {
    expect(filterAchievements(all, { ...NO_FILTERS, search: "HABITUE" }).map((d) => d.key)).toEqual(["regular"]);
    expect(filterAchievements(all, { ...NO_FILTERS, status: "inactive" }).map((d) => d.key)).toEqual(["regular"]);
    expect(filterAchievements(all, { ...NO_FILTERS, family: "progression" }).map((d) => d.key)).toEqual(["witch_of_envy"]);
    expect(filterAchievements(all, { ...NO_FILTERS, rewardOnly: true }).map((d) => d.key)).toEqual(["witch_of_envy"]);
  });

  test("ordering is only offered on the whole list", () => {
    expect(isFiltered(NO_FILTERS)).toBe(false);
    expect(isFiltered({ ...NO_FILTERS, search: " " })).toBe(false);
    expect(isFiltered({ ...NO_FILTERS, family: "parties" })).toBe(true);
  });
});
