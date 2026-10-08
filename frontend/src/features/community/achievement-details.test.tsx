import { renderToStaticMarkup } from "react-dom/server";

import { AchievementTile, ProgressTree, conditionPercent, conditionTarget } from "./achievement-details";
import { isProgressNode, type ProgressCondition, type ProgressGroup } from "./achievement-progress-api";

const condition = (over: Partial<ProgressCondition>): ProgressCondition => ({
  type: "condition", fact: "itemsFromOthers", label: "Items reçus d'autres joueurs (hors release et collect)", operator: ">=", value: 3000, value2: null, current: 1240, met: false, ...over,
});

/** Story 30.53: where the member stands on each condition of an achievement. */
describe("achievement progress", () => {
  test("a condition reads its target, and a bar only where « at least » means something", () => {
    expect(conditionTarget(condition({}))).toMatch(/^au moins 3\s000$/);
    expect(conditionTarget(condition({ operator: "between", value: 500, value2: 2000 }))).toMatch(/^entre 500 et 2\s000$/);
    expect(conditionPercent(condition({}))).toBe(41);
    expect(conditionPercent(condition({ current: 2999 }))).toBe(99);
    expect(conditionPercent(condition({ current: 3000, met: true }))).toBe(100);
    expect(conditionPercent(condition({ operator: ">", value: 0, current: 0 }))).toBe(0);
    expect(conditionPercent(condition({ operator: "<=" }))).toBeNull();
  });

  test("the tree shows each condition, its value, and how a group combines them", () => {
    const tree: ProgressGroup = {
      type: "group", op: "all", met: false,
      rules: [condition({}), { type: "group", op: "none", met: true, rules: [condition({ fact: "goals", label: "Objectifs atteints", value: 1, current: 0 })] }],
    };
    const html = renderToStaticMarkup(<ProgressTree group={tree} root />);
    expect(html).toContain("Toutes ces conditions");
    expect(html).toContain("Items reçus d&#x27;autres joueurs<");
    expect(html).toMatch(/1\s240/);
    expect(html).toContain('aria-valuenow="41"');
    // Under « aucune », the condition is on track while it does not hold, and says so.
    expect(html).toContain("Aucune de ces conditions");
    expect(html).toContain("pas au moins 1");
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
