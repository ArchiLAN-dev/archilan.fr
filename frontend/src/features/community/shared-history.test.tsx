import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { SharedHistory } from "./community-friends-api";
import { itemsLine, relaunchHref, SharedHistoryBlock, sharedSummary } from "./shared-history";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function history(extra: Partial<SharedHistory> = {}): SharedHistory {
  return {
    userId: "u-other",
    isFriend: true,
    count: 3,
    firstAt: "2026-03-02T18:00:00+00:00",
    lastAt: "2026-05-01T18:00:00+00:00",
    latest: [
      { sessionId: "s2", kind: "run", title: "Run secrète", playedAt: "2026-05-01T18:00:00+00:00", recap: false },
      { sessionId: "s1", kind: "event", title: "LAN", playedAt: "2026-03-02T18:00:00+00:00", recap: true },
    ],
    items: { sent: 12, received: 1, since: "2026-03-02T12:00:00+00:00" },
    ...extra,
  };
}

function render(data: SharedHistory | null): string {
  const client = new QueryClient();
  client.setQueryData(["shared-history", "other"], data);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <SharedHistoryBlock name="Other" slug="other" />
    </QueryClientProvider>,
  );
}

describe("shared history (story 43.9)", () => {
  beforeEach(() => {
    mockUser = { id: "me" };
  });

  it("sums up the sessions and the items exchanged", () => {
    expect(sharedSummary(history())).toBe("3 parties, du 2 mars 2026 au 1 mai 2026");
    expect(sharedSummary(history({ count: 1 }))).toBe("1 partie, le 1 mai 2026");
    expect(itemsLine(history())).toBe("Items échangés : 12 envoyés, 1 reçu (depuis le 2 mars 2026)");
    expect(itemsLine(history({ items: { sent: 0, received: 0, since: null } }))).toBeNull();
  });

  it("links a recap only when the viewer may open it, and offers to play again with a friend", () => {
    const html = render(history());
    expect(html).toContain("Vous avez joué ensemble");
    expect(html).toContain('href="/parties/s1"');
    expect(html).not.toContain('href="/parties/s2"');
    expect(html).toContain(`href="${relaunchHref("u-other")}"`);
  });

  it("asks a non-friend to be added first", () => {
    const html = render(history({ isFriend: false }));
    expect(html).toContain("Ajouter en ami pour rejouer");
    expect(html).not.toContain("Relancer une partie ensemble");
  });

  it("shows nothing without a shared session, or to a visitor", () => {
    expect(render(null)).toBe("");
    mockUser = null;
    expect(render(history())).toBe("");
  });
});
