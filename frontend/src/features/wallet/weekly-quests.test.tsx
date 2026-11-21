import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { ChestRow, ObjectiveBar, QuestHistory, StreakRow, WeeklyQuestsPanel, WeeklyQuestsView, fetchMyQuests, questMaxReward, type WeeklyQuests } from "./weekly-quests";
import { pelleReasonLabel } from "./wallet-api";

const quests: WeeklyQuests = {
  week: "2026-W40",
  renewsAt: "2026-10-04T22:00:00+00:00",
  quests: [
    { key: "reach_a_goal", label: "Atteindre un goal", description: "", reward: 40, done: true, paid: true, objectives: [{ metric: "goals", label: "Goals atteints", unit: "goals", target: 1, current: 1, duration: false }] },
    { key: "play_with_someone_new", label: "Jouer avec quelqu'un de nouveau", description: "", reward: 30, done: true, paid: false, objectives: [{ metric: "newPartners", label: "Nouveaux partenaires de jeu", unit: "nouveaux partenaires", target: 1, current: 1, duration: false }] },
    {
      key: "marathon",
      label: "Marathon",
      description: "Joue beaucoup cette semaine.",
      reward: 60,
      done: false,
      paid: false,
      objectives: [{ metric: "checks", label: "Checks faits", unit: "checks", target: 50, current: 12, duration: false }, { metric: "sessions", label: "Parties jouées", unit: "parties", target: 2, current: 3, duration: false }],
    },
  ],
  chest: { reward: 50, done: 2, total: 3, paid: false },
  history: [
    { week: "2026-W39", startsAt: "2026-09-20T22:00:00+00:00", endsAt: "2026-09-27T22:00:00+00:00", done: 3, served: 3, chest: true, pelles: 150 },
    { week: "2026-W38", startsAt: "2026-09-13T22:00:00+00:00", endsAt: "2026-09-20T22:00:00+00:00", done: 0, served: 0, chest: false, pelles: 0 },
  ],
};

/** Story 41.6: the quests of the week. */
describe("weekly quests", () => {
  test("shows each quest, done or not, and the renewal day", () => {
    const html = renderToStaticMarkup(<WeeklyQuestsView quests={quests} />);

    expect(html).toContain("Quêtes de la semaine");
    expect(html).toContain("Nouvelles quêtes le lundi 5 octobre");
    expect(html).toContain("+40");
    expect(html).toContain("créditée dans quelques minutes");
    expect(html.match(/créditée dans/g)).toHaveLength(1);
    expect(html).toContain("à faire");
  });

  test("each objective has its bar, capped at the target (story 41.15)", () => {
    const html = renderToStaticMarkup(<WeeklyQuestsView quests={quests} />);

    expect(html).toContain("Joue beaucoup cette semaine.");
    expect(html).toContain("12 / 50 checks");
    expect(html).toContain("width:24%");
    expect(html).toContain("2 / 2 parties");
    expect(html.match(/role="progressbar"/g)).toHaveLength(4);
    expect(renderToStaticMarkup(<WeeklyQuestsView quests={{ ...quests, quests: [] }} />)).toContain("Pas de quête cette semaine.");
  });

  test("the chest tells how many quests are left, then that it is open (story 41.16)", () => {
    const html = renderToStaticMarkup(<ChestRow chest={{ reward: 50, done: 2, total: 3, paid: false }} />);
    expect(html).toContain("Coffre de la semaine");
    expect(html).toContain("(2 / 3)");
    expect(html).toContain("+50");

    expect(renderToStaticMarkup(<ChestRow chest={{ reward: 50, done: 3, total: 3, paid: true }} />)).toContain("Ouvert");
    expect(renderToStaticMarkup(<WeeklyQuestsView quests={{ ...quests, chest: null }} />)).not.toContain("Coffre");
  });

  test("the history is its own block, apart from the quests in progress (stories 41.17, 41.21)", () => {
    const html = renderToStaticMarkup(<QuestHistory weeks={quests.history} />);
    expect(html).toContain("Historique des quêtes");
    expect(html).toContain("coffre ouvert");
    expect(html).toContain("Sem. du 21 sept.");
    expect(html).toContain("3 / 3 quêtes");
    expect(html).toContain("coffre");
    expect(html).toContain("+150");
    expect(html).toContain("Pas de quête");

    expect(renderToStaticMarkup(<WeeklyQuestsView quests={quests} />)).not.toContain("Historique des quêtes");
  });

  test("an objective aimed at a game says which (story 41.18)", () => {
    const html = renderToStaticMarkup(
      <ObjectiveBar done={false} objective={{ metric: "goals", label: "Goals atteints", unit: "goal", target: 1, current: 0, duration: false, scope: "Hollow Knight" }} />,
    );
    expect(html).toContain("Goals atteints");
    expect(html).toContain(" · Hollow Knight");
    expect(html).toContain("0 / 1 goal");
  });

  test("the game aimed at links its page (story 41.67)", () => {
    const html = renderToStaticMarkup(
      <ObjectiveBar done={false} objective={{ metric: "goals", label: "Goals atteints", unit: "goals", target: 2, current: 0, duration: false, scope: "Hollow Knight", gameSlug: "hollow-knight" }} />,
    );
    expect(html).toContain('href="/jeux/hollow-knight"');
    expect(html).toContain(">Hollow Knight</a>");
  });

  test("a quest with tiers shows each one on the same bar (story 41.68)", () => {
    const tiers = [
      { target: 2, reward: 10, reached: true, paid: true },
      { target: 4, reward: 20, reached: false, paid: false },
    ];
    const html = renderToStaticMarkup(
      <ObjectiveBar done objective={{ metric: "checks", label: "Checks faits", unit: "checks", target: 2, current: 3, duration: false }} tiers={tiers} />,
    );
    expect(html).toContain("3 / 4 checks");
    expect(html).toContain("Palier atteint : ");
    expect(html).toContain('aria-label="Paliers"');
    expect(questMaxReward({ key: "q", label: "Toujours plus", description: "", reward: 10, done: true, paid: true, objectives: [], tiers })).toBe(30);
  });

  test("time played shows hours on both sides of its bar (story 41.64)", () => {
    const html = renderToStaticMarkup(
      <ObjectiveBar done={false} objective={{ metric: "playMinutes", label: "Temps de jeu", unit: "de jeu", target: 360, current: 150, duration: true }} />,
    );
    expect(html).toContain("2 h 30 / 6 h de jeu");
  });

  test("reads the quests, and the reason has a label", async () => {
    server.use(http.get(`${TEST_API_BASE_URL}/me/quests`, () => HttpResponse.json(quests)));

    expect(await fetchMyQuests()).toEqual(quests);
    expect(pelleReasonLabel("quest_reward")).toBe("Quête de la semaine");
  });
});

/** Story 41.34: the run of chests in the quests panel. */
describe("quest streak", () => {
  test("a run in progress, still to extend this week, with the record when it is higher", () => {
    const html = renderToStaticMarkup(<StreakRow streak={{ current: 3, best: 5, thisWeek: false }} />);

    expect(html).toContain("Série : 3 semaines d&#x27;affilée");
    expect(html).toContain("Ouvre le coffre de cette semaine pour la prolonger.");
    expect(html).toContain("Record : 5 semaines");
  });

  test("a run already extended this week asks nothing more and hides an equal record", () => {
    const html = renderToStaticMarkup(<StreakRow streak={{ current: 1, best: 1, thisWeek: true }} />);

    expect(html).toContain("Série : 1 semaine d&#x27;affilée");
    expect(html).not.toContain("Ouvre le coffre");
    expect(html).not.toContain("Record");
  });

  test("no run yet says how to start one", () => {
    const html = renderToStaticMarkup(<StreakRow streak={{ current: 0, best: 2, thisWeek: false }} />);

    expect(html).toContain("Pas de série en cours");
    expect(html).toContain("Ouvre le coffre de la semaine pour lancer une série.");
    expect(html).toContain("Record : 2 semaines");
  });

  test("the panel shows it under the chest, not when there is neither chest nor run, nor from an older API", () => {
    expect(renderToStaticMarkup(<WeeklyQuestsView quests={{ ...quests, streak: { current: 2, best: 2, thisWeek: false } }} />)).toContain("Série : 2 semaines");
    expect(renderToStaticMarkup(<WeeklyQuestsView quests={{ ...quests, chest: null, streak: { current: 0, best: 4, thisWeek: false } }} />)).not.toContain("série");
    expect(renderToStaticMarkup(<WeeklyQuestsView quests={quests} />)).not.toContain("Série");
  });
});

/** Hotfix v1.0.1: the quests block says it loads, and says when it failed, instead of staying empty. */
describe("weekly quests panel", () => {
  function panel(client: QueryClient): string {
    return renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <WeeklyQuestsPanel />
      </QueryClientProvider>,
    );
  }

  test("says the quests are loading", () => {
    expect(panel(new QueryClient())).toContain("Chargement des quêtes de la semaine");
  });

  /** A query already failed: kept failed on mount, as after the request. */
  function failing(): QueryClient {
    return new QueryClient({ defaultOptions: { queries: { retryOnMount: false } } });
  }

  function failed(client: QueryClient, queryKey: string[]): void {
    client.getQueryCache().build(client, { queryKey }).setState({ status: "error", error: new Error("503"), fetchStatus: "idle" });
  }

  test("says when they could not be loaded, with a way to retry", () => {
    const client = failing();
    client.setQueryData(["my-quests"], null);
    failed(client, ["my-quests", "progress"]);

    const html = panel(client);
    expect(html).toContain("Impossible de charger les quêtes de la semaine");
    expect(html).toContain("Réessayer");
  });

  test("shows the quests once loaded", () => {
    const client = new QueryClient();
    client.setQueryData(["my-quests"], quests);

    expect(panel(client)).toContain("Quêtes de la semaine");
  });

  /** Story 41.73: the quests at once, their progress after. */
  const fast: WeeklyQuests = {
    ...quests,
    progress: false,
    quests: quests.quests.map((quest) => ({ ...quest, done: quest.paid, objectives: quest.objectives.map((objective) => ({ ...objective, current: null })) })),
    chest: { reward: 50, done: null, total: 3, paid: false },
  };

  test("shows the quests before their progress, with a skeleton for each count", () => {
    const client = new QueryClient();
    client.setQueryData(["my-quests"], fast);

    const html = panel(client);
    expect(html).toContain("Marathon");
    expect(html).toContain("Calcul de ta progression");
    expect(html).toContain('data-testid="objective-skeleton"');
    expect(html).toContain("motion-reduce:animate-none");
    expect(html).not.toContain("12 / 50");
    expect(html).not.toContain("Progression indisponible");
  });

  test("a paid quest shows full before its progress", () => {
    const html = renderToStaticMarkup(<WeeklyQuestsView quests={fast} />);
    // « Atteindre un goal » is paid: its bar is full, no skeleton for it.
    expect(html).toContain("1 / 1 goals");
  });

  test("keeps the quests when the progress fails, and offers to retry", () => {
    const client = failing();
    client.setQueryData(["my-quests"], fast);
    failed(client, ["my-quests", "progress"]);

    const html = panel(client);
    expect(html).toContain("Marathon");
    expect(html).toContain("Progression indisponible pour le moment");
    expect(html).toContain("Réessayer");
    expect(html).not.toContain("animate-pulse rounded-full");
  });

  test("shows the counts once the progress is read", () => {
    const client = new QueryClient();
    client.setQueryData(["my-quests"], fast);
    client.setQueryData(["my-quests", "progress"], { ...quests, progress: true });

    const html = panel(client);
    expect(html).toContain("12 / 50");
    expect(html).not.toContain('data-testid="objective-skeleton"');
  });
});
