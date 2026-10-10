import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import { formatMargin, weeklyDuelResultTitle } from "@/features/community/notification-content";
import { weeklyDuelsKey, type DuelStanding, type WeeklyDuel } from "./weekly-duels-api";
import { duelStandingLabel, WeeklyDuels } from "./weekly-duels";

const mockUser: Partial<AuthUser> | null = { id: "me" };
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function standing(slug: string, status: DuelStanding["status"], seconds: number | null = null, extra: Partial<DuelStanding> = {}): DuelStanding {
  const name = slug.charAt(0).toUpperCase() + slug.slice(1);
  return { userId: `u-${slug}`, slug, displayName: name, avatarUrl: null, status, completionTimeSeconds: seconds, isViewer: false, isCreator: false, ...extra };
}

function render(duels: WeeklyDuel[], weeklyRunId: string | null = "wr1"): string {
  const client = new QueryClient();
  client.setQueryData(weeklyDuelsKey(weeklyRunId), duels);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <WeeklyDuels weeklyRunId={weeklyRunId} />
    </QueryClientProvider>,
  );
}

const text = (segments: { text: string }[]) => segments.map((s) => s.text).join("");

/** Story 43.15: duels between friends on a weekly run. */
describe("weekly duels", () => {
  it("says where each member stands", () => {
    expect(duelStandingLabel(standing("a", "goal", 3725))).toBe("01:02:05");
    expect(duelStandingLabel(standing("a", "launched"))).toBe("Lancée");
    expect(duelStandingLabel(standing("a", "invited"))).toBe("Pas encore répondu");
    expect(duelStandingLabel(standing("a", "none"))).toBe("Pas inscrit");
  });

  it("puts the gap in words", () => {
    expect(formatMargin(40)).toBe("40 s");
    expect(formatMargin(720)).toBe("12 min");
    expect(formatMargin(3900)).toBe("1 h 05");
    expect(formatMargin(7200)).toBe("2 h");
  });

  it("tells the result from the member's side", () => {
    expect(text(weeklyDuelResultTitle({ outcome: "won", opponentName: "Alice", marginSeconds: 720, gameName: "Hollow Knight" }))).toBe("Tu bats Alice de 12 min sur Hollow Knight");
    expect(text(weeklyDuelResultTitle({ outcome: "lost", opponentName: "Alice", marginSeconds: 90, gameName: "" }))).toBe("Alice remporte le duel hebdo (2 min devant toi)");
    expect(text(weeklyDuelResultTitle({ outcome: "none", gameName: "" }))).toBe("Personne n'a atteint l'objectif : pas de gagnant pour ce duel");
  });

  it("asks a challenged member to answer, and ranks the duel", () => {
    const html = render([
      {
        duelId: "d1",
        weeklyRunId: "wr1",
        gameName: "Hollow Knight",
        isCreator: false,
        myStatus: "pending",
        standings: [standing("alice", "goal", 900, { isCreator: true }), standing("me", "invited", null, { isViewer: true })],
      },
    ]);

    expect(html).toContain("Duel de Alice");
    expect(html).toContain("Relever le défi");
    expect(html).toContain("00:15:00");
    expect(html).toContain("(toi)");
  });

  it("shows nothing without a duel", () => {
    expect(render([])).toBe("");
  });
});
