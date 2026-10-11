import { hrefFor, messageFor } from "./notification-center";
import { contentFor } from "./notification-content";
import type { NotificationItem } from "./notifications-api";

function item(over: Partial<NotificationItem> & { type: string }): NotificationItem {
  return { id: over.type, createdAt: new Date(2026, 9, 11, 18, 0).toISOString(), read: false, actor: null, data: {}, details: null, ...over };
}

const bob = { slug: "bob", displayName: "Bob", avatarUrl: null };

/** Story 43.28: the moments of a personal run in the bell. */
describe("run moment notifications", () => {
  test("the games a player chose are named, and lead to the run", () => {
    const chosen = item({ type: "run_games_chosen", actor: bob, data: { runId: "r1", runTitle: "Ma run", games: ["Hollow Knight", "Celeste"] } });

    expect(contentFor(chosen, "")).toMatchObject({
      label: "Partie",
      title: [{ text: "Bob", strong: true }, { text: " a choisi ses jeux" }, { text: " dans " }, { text: "Ma run", strong: true }],
      detail: "Hollow Knight, Celeste",
    });
    expect(messageFor(chosen)).toBe("Bob a choisi ses jeux dans « Ma run »");
    expect(hrefFor(chosen)).toBe("/runs/r1");
  });

  test("the start and the recap read without a title too", () => {
    expect(messageFor(item({ type: "run_started", data: { runId: "r1", runTitle: "Ma run" } }))).toBe("« Ma run » est lancée : à toi de jouer");
    expect(messageFor(item({ type: "run_started" }))).toBe("Ta partie est lancée : à toi de jouer");
    expect(messageFor(item({ type: "run_recap_ready", data: { runId: "r1", runTitle: "Ma run" } }))).toBe("Le récap de « Ma run » est prêt");
    expect(contentFor(item({ type: "run_recap_ready" }), "").title).toEqual([{ text: "Le récap de " }, { text: "ta partie" }, { text: " est prêt" }]);
    expect(hrefFor(item({ type: "run_recap_ready", data: { runId: "r1" } }))).toBe("/runs/r1");
    expect(hrefFor(item({ type: "run_started" }))).toBe("/compte/parties");
  });
});
