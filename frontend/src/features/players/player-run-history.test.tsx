import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { PlayerHistory, RunHistoryEntry } from "./player-profile-api";
import { PlayerRunHistory } from "./player-run-history";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function entry(eventName: string, extra: Partial<RunHistoryEntry> = {}): RunHistoryEntry {
  return {
    sessionId: `s-${eventName}`,
    eventName,
    finishedAt: "2026-10-01T12:00:00Z",
    game: "Game",
    checksDone: 10,
    itemsReceived: 5,
    goalReachedAt: null,
    wasReleased: false,
    isInvalidated: false,
    isWeekly: false,
    ...extra,
  };
}

function history(...entries: RunHistoryEntry[]): PlayerHistory {
  return { data: entries, meta: { page: 1, limit: 100, total: entries.length } };
}

function render(initial: PlayerHistory | null, own?: PlayerHistory): string {
  const client = new QueryClient();
  if (own !== undefined) {
    client.setQueryData(["player-own-history", "dana"], own);
  }
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <PlayerRunHistory history={initial} slug="dana" />
    </QueryClientProvider>,
  );
}

describe("PlayerRunHistory (story 32.22)", () => {
  afterEach(() => {
    mockUser = null;
  });

  it("shows a visitor the public history of the server render", () => {
    const html = render(history(entry("LAN publique")), history(entry("LAN publique"), entry("Secrète", { isPrivate: true })));

    expect(html).toContain("LAN publique");
    expect(html).not.toContain("Secrète");
  });

  it("shows the player their own history, private runs marked", () => {
    mockUser = { slug: "dana" };

    const html = render(history(entry("LAN publique")), history(entry("LAN publique"), entry("Secrète", { isPrivate: true })));

    expect(html).toContain("Secrète");
    expect(html.match(/>Privée</g)).toHaveLength(1);
  });

  it("keeps the server history while the player's own one loads", () => {
    mockUser = { slug: "dana" };

    expect(render(history(entry("LAN publique")))).toContain("LAN publique");
  });

  it("says when the history is unavailable", () => {
    expect(render(null)).toContain("temporairement indisponible");
  });
});
