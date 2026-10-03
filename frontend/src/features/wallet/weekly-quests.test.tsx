import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { WeeklyQuestsView, fetchMyQuests, type WeeklyQuests } from "./weekly-quests";
import { pelleReasonLabel } from "./wallet-api";

const quests: WeeklyQuests = {
  week: "2026-W40",
  renewsAt: "2026-10-04T22:00:00+00:00",
  quests: [
    { key: "reach_a_goal", label: "Atteindre un goal", reward: 40, done: true, paid: true },
    { key: "play_with_someone_new", label: "Jouer avec quelqu'un de nouveau", reward: 30, done: true, paid: false },
    { key: "play_a_weekly", label: "Faire une hebdo", reward: 30, done: false, paid: false },
  ],
};

/** Story 41.6: the quests of the week. */
describe("weekly quests", () => {
  test("shows each quest, done or not, and the renewal day", () => {
    const html = renderToStaticMarkup(<WeeklyQuestsView quests={quests} />);

    expect(html).toContain("Quêtes de la semaine");
    expect(html).toContain("Nouvelles quêtes le lundi 5 octobre");
    expect(html).toContain("+40");
    expect(html).toContain("créditée dans l&#x27;heure");
    expect(html.match(/créditée dans/g)).toHaveLength(1);
    expect(html).toContain("à faire");
  });

  test("reads the quests, and the reason has a label", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/me/quests`, () => HttpResponse.json(quests)));

    expect(await fetchMyQuests()).toEqual(quests);
    expect(pelleReasonLabel("quest_reward")).toBe("Quête de la semaine");
  });
});
