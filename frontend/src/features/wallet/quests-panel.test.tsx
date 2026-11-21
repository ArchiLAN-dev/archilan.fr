import { renderToStaticMarkup } from "react-dom/server";

import { QuestsSummaryView, weekMaxReward } from "./quests-panel";
import type { WeeklyQuests } from "./weekly-quests";

const week: WeeklyQuests = {
  week: "2026-W47",
  renewsAt: "2026-11-22T23:00:00+00:00",
  progress: false,
  quests: [
    { key: "a", label: "A", description: "", reward: 40, done: false, paid: false, objectives: [] },
    {
      key: "b",
      label: "B",
      description: "",
      reward: 10,
      done: false,
      paid: false,
      objectives: [],
      tiers: [
        { target: 2, reward: 10, reached: false, paid: false },
        { target: 4, reward: 20, reached: false, paid: false },
      ],
    },
  ],
  chest: { reward: 50, done: null, total: 2, paid: false },
  history: [],
};

/** Story 41.73: on « Pelles », the quests in a line and the way to their page. */
describe("quests summary", () => {
  test("adds every quest, tier and the chest", () => {
    expect(weekMaxReward(week)).toBe(40 + 30 + 50);
  });

  test("tells the quests of the week and leads to their page", () => {
    const html = renderToStaticMarkup(<QuestsSummaryView quests={week} />);
    expect(html).toContain("2 quêtes");
    expect(html).toContain("avec le coffre");
    expect(html).toContain("nouvelles quêtes le lundi 23 novembre");
    expect(html).toContain('href="/compte/quetes"');
  });

  test("still leads to the quests when they could not be read", () => {
    const html = renderToStaticMarkup(<QuestsSummaryView quests={null} />);
    expect(html).toContain("de nouvelles quêtes chaque lundi");
    expect(html).toContain('href="/compte/quetes"');
  });
});
