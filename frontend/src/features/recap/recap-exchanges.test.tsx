import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { ExchangePlayer, RecapExchanges as Data, SlotExchange } from "./recap-exchanges-api";
import { exchangeLine, playersLabel, RecapExchanges, unblockLine } from "./recap-exchanges";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function player(slug: string, isFriend: boolean): ExchangePlayer {
  return { userId: `u-${slug}`, slug, displayName: slug[0].toUpperCase() + slug.slice(1), avatarUrl: null, isFriend };
}

function exchange(slotName: string, players: ExchangePlayer[], extra: Partial<SlotExchange> = {}): SlotExchange {
  return { slotName, players, hasFriend: players.some((p) => p.isFriend), sent: 3, received: 2, sentProgression: 1, receivedProgression: 0, ...extra };
}

function render(data: Data | null): string {
  const client = new QueryClient();
  client.setQueryData(["recap-exchanges", "s1"], data);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <RecapExchanges sessionId="s1" />
    </QueryClientProvider>,
  );
}

describe("recap exchanges (story 43.10)", () => {
  beforeEach(() => {
    mockUser = { id: "me" };
  });

  it("names a co-played slot by all its players", () => {
    expect(playersLabel([player("alice", true)], "AliceHK")).toBe("Alice");
    expect(playersLabel([player("alice", true), player("bob", false)], "HK")).toBe("Alice et Bob");
    expect(playersLabel([], "Solo")).toBe("Solo");
  });

  it("counts the items each way, with the progression ones", () => {
    expect(exchangeLine(exchange("A", []))).toBe("3 envoyés (1 de progression), 2 reçus");
    expect(exchangeLine(exchange("A", [], { sent: 1, sentProgression: 0, received: 1 }))).toBe("1 envoyé, 1 reçu");
  });

  it("tells who got the viewer out of a BK", () => {
    const html = render({
      exchanges: [exchange("AliceHK", [player("alice", true)]), exchange("BobHK", [player("bob", false)])],
      unblocks: [{ slotName: "MeHK", itemName: "Grappin", senderName: "AliceHK", at: "2026-09-01T10:04:30+00:00", senders: [player("alice", true)] }],
    });
    expect(html).toContain("Entre nous");
    expect(html).toContain("Alice t&#x27;a envoyé Grappin qui t&#x27;a sorti du BK");
    expect(html).toContain("Ajouter Bob en ami");
    expect(html).not.toContain("Ajouter Alice en ami");
    expect(unblockLine({ slotName: "M", itemName: null, senderName: "X", at: "", senders: [] })).toBe("X t'a envoyé un objet qui t'a sorti du BK");
  });

  it("shows nothing to someone who did not play, or to a visitor", () => {
    expect(render(null)).toBe("");
    mockUser = null;
    expect(render({ exchanges: [exchange("A", [])], unblocks: [] })).toBe("");
  });
});
