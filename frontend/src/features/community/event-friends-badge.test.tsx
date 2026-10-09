import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { FriendCard } from "./community-friends-api";
import { EventFriendsBadge, eventFriendsLine } from "./event-friends-badge";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function card(slug: string): FriendCard {
  return { userId: `u-${slug}`, slug, displayName: slug.toUpperCase(), avatarUrl: null };
}

function render(node: React.ReactNode, seed: (client: QueryClient) => void): string {
  const client = new QueryClient();
  seed(client);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

describe("event friends badge (story 43.4)", () => {
  beforeEach(() => {
    mockUser = { id: "me" };
  });

  it("says how many friends come", () => {
    expect(eventFriendsLine(1)).toBe("1 de tes amis participe");
    expect(eventFriendsLine(4)).toBe("4 de tes amis participent");
  });

  it("shows three avatars and a counter on an event page", () => {
    const html = render(<EventFriendsBadge eventId="e1" />, (client) =>
      client.setQueryData(["event-friends", "e1"], ["a", "b", "c", "d", "e"].map(card)),
    );

    expect(html).toContain("5 de tes amis participent");
    expect(html).toContain("+2");
    expect(html).toContain("A, B, C, D, E");
  });

  it("reads its event from the grouped call on a list", () => {
    const html = render(<EventFriendsBadge eventId="e2" eventIds={["e1", "e2"]} />, (client) =>
      client.setQueryData(["event-friends-batch", ["e1", "e2"]], { e2: [card("bob")] }),
    );

    expect(html).toContain("1 de tes amis participe");
    expect(html).not.toContain("+");
  });

  it("shows nothing without a friend or to a visitor", () => {
    const seed = (client: QueryClient) => client.setQueryData(["event-friends-batch", ["e1"]], {});
    expect(render(<EventFriendsBadge eventId="e1" eventIds={["e1"]} />, seed)).toBe("");

    mockUser = null;
    expect(
      render(<EventFriendsBadge eventId="e1" />, (client) => client.setQueryData(["event-friends", "e1"], [card("a")])),
    ).toBe("");
  });
});
