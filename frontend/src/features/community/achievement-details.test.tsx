import { renderToStaticMarkup } from "react-dom/server";

import { AchievementTile, ProgressTree, conditionPercent, conditionsMet, progressScore } from "./achievement-details";
import { conditionPhrase } from "./achievement-phrasing";
import { isProgressNode, type ProgressCondition, type ProgressGroup } from "./achievement-progress-api";

const condition = (over: Partial<ProgressCondition>): ProgressCondition => ({
  type: "condition", fact: "itemsFromOthers", label: "Items reçus d'autres joueurs (hors release et collect)", operator: ">=", value: 3000, value2: null, current: 1240, met: false, ...over,
});

/** Stories 30.53 and 30.54: where the member stands on each condition of an achievement. */
describe("achievement progress", () => {
  test("a bar only where « at least » means something", () => {
    expect(conditionPercent(condition({}))).toBe(41);
    expect(conditionPercent(condition({ current: 2999 }))).toBe(99);
    expect(conditionPercent(condition({ current: 3000, met: true }))).toBe(100);
    expect(conditionPercent(condition({ operator: ">", value: 0, current: 0 }))).toBe(0);
    expect(conditionPercent(condition({ operator: "<=" }))).toBeNull();
  });

  const tree: ProgressGroup = {
    type: "group", op: "all", met: false,
    rules: [
      condition({}),
      { type: "group", op: "none", met: true, rules: [condition({ fact: "goals", label: "Objectifs atteints", value: 1, current: 0 })] },
      condition({ fact: "runs", label: "Parties jouées", value: 10, current: 10, met: true }),
    ],
  };

  test("the whole rule as one score, and the conditions met counted as the rule reads them", () => {
    // (41 + 100 + 100) / 3, never 100 before the rule holds.
    expect(progressScore(tree)).toBe(80);
    expect(progressScore({ ...tree, op: "any" })).toBe(99);
    expect(progressScore({ ...tree, met: true })).toBe(100);
    expect(conditionsMet(tree)).toEqual({ met: 2, total: 3 });
  });

  test("the tree draws each condition as a sentence, with its value and a bar", () => {
    const html = renderToStaticMarkup(<ProgressTree group={tree} root />);
    expect(html).toContain("Toutes ces conditions");
    expect(html).toMatch(/Recevoir 3\s000 items d&#x27;autres joueurs/);
    expect(html).toMatch(/1\s240/);
    expect(html).toContain('aria-valuenow="41"');
    expect(html).toMatch(/Encore 1\s760/);
    expect(html).toContain("Aucune de ces conditions");
    expect(html).toContain("Ne pas atteindre un objectif");
    expect(html).toContain('aria-label="Rempli"');
  });

  test("the API's tree is checked node by node", () => {
    expect(isProgressNode({ type: "group", op: "any", met: false, rules: [condition({})] })).toBe(true);
    expect(isProgressNode({ type: "group", op: "xor", met: false, rules: [] })).toBe(false);
    expect(isProgressNode({ ...condition({}), current: "12" })).toBe(false);
  });

  test("a tile opens its details as a dialog", () => {
    const html = renderToStaticMarkup(
      <AchievementTile
        achievement={{ key: "witch_of_envy", name: "Je t'aime", description: "", unlocked: false, unlockedAt: null, grantId: null, kudosCount: 0, customImageUrl: null }}
        slug="alice"
      />,
    );
    expect(html).toContain('aria-haspopup="dialog"');
  });
});

describe("condition phrasing", () => {
  const say = (over: Partial<ProgressCondition>, negated = false) => conditionPhrase(condition(over), negated).replace(/\s/g, " ");

  test("each criterion reads as what the member does", () => {
    expect(say({ fact: "runs", value: 10 })).toBe("Jouer 10 parties");
    expect(say({ fact: "runs", value: 1 })).toBe("Jouer une partie");
    expect(say({ fact: "distinctGames", value: 5 })).toBe("Jouer à 5 jeux différents");
    expect(say({})).toBe("Recevoir 3 000 items d'autres joueurs (hors release et collect)");
    expect(say({ fact: "questsCompleted", value: 1 })).toBe("Réussir une quête de la semaine");
    expect(say({ fact: "questChestStreak", value: 4 })).toBe("Ouvrir le coffre des quêtes 4 semaines d'affilée");
    // Story 43.16: playing with others.
    expect(say({ fact: "distinctFriendsPlayedWith", value: 5 })).toBe("Jouer avec 5 amis différents");
    expect(say({ fact: "maxFinishedWithSamePerson", value: 3 })).toBe("Terminer 3 parties avec la même personne");
    expect(say({ fact: "weeklyDuelsWon", value: 1 })).toBe("Gagner un duel hebdo");
  });

  test("events and superlatives by their name, the count only when it says something", () => {
    expect(say({ fact: "event_goal:lan3", label: "Objectif atteint à « ArchiLAN #3 »", value: 1 })).toBe("Atteindre son objectif à « ArchiLAN #3 »");
    expect(say({ fact: "superlative:most_generous", label: "Superlatif « Le Parrain » (le plus généreux)", value: 1 })).toBe("Remporter le superlatif « Le Parrain »");
    expect(say({ fact: "superlative:most_generous", label: "Superlatif « Le Parrain » (le plus généreux)", value: 3 })).toBe("Remporter le superlatif « Le Parrain » 3 fois");
  });

  test("the other comparisons, the negation, and an unknown criterion", () => {
    expect(say({ fact: "checks", operator: "between", value: 500, value2: 2000 })).toBe("Compléter entre 500 et 2 000 checks");
    expect(say({ fact: "goals", operator: ">", value: 0 })).toBe("Atteindre plus de 0 objectifs");
    expect(say({ fact: "goals", value: 1 }, true)).toBe("Ne pas atteindre un objectif");
    expect(say({ fact: "mystery", label: "Mystère", value: 2 })).toBe("Mystère : 2");
  });
});
