import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { FriendPlaying, FriendRecent, FriendsNow } from "./community-friends-api";
import { FRIENDS_NOW_KEY, FriendsNowCard, sessionHref } from "./friends-now-card";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function playing(slug: string, extra: Partial<FriendPlaying> = {}): FriendPlaying {
  return {
    userId: `u-${slug}`,
    slug,
    displayName: slug.toUpperCase(),
    avatarUrl: null,
    game: "Hollow Knight",
    kind: "event",
    title: null,
    eventId: null,
    runId: null,
    ...extra,
  };
}

function recent(slug: string, finishedAt: string): FriendRecent {
  return { userId: `u-${slug}`, slug, displayName: slug.toUpperCase(), avatarUrl: null, game: "Celeste", finishedAt };
}

function render(data: FriendsNow): string {
  const client = new QueryClient();
  client.setQueryData(FRIENDS_NOW_KEY, data);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <FriendsNowCard />
    </QueryClientProvider>,
  );
}

describe("friends now card (story 43.5)", () => {
  beforeEach(() => {
    mockUser = { id: "me" };
  });

  it("links a session only when the viewer has access to it", () => {
    expect(sessionHref(playing("a", { eventId: "e1" }))).toBe("/evenements/e1");
    expect(sessionHref(playing("b", { kind: "run", runId: "r1" }))).toBe("/runs/r1");
    expect(sessionHref(playing("c"))).toBeNull();
  });

  it("shows the friends playing, then those active recently", () => {
    const html = render({
      hasFriends: true,
      playing: [playing("alice", { title: "LAN d'automne", eventId: "e1" }), playing("bob", { kind: "run" })],
      recent: [recent("carol", new Date(Date.now() - 2 * 3_600_000).toISOString())],
    });

    expect(html).toContain("Mes amis en ce moment");
    expect(html).toContain('href="/evenements/e1"');
    expect(html).toContain("Hollow Knight · Run perso");
    expect(html).toContain("En jeu");
    expect(html).toContain("CAROL");
    expect(html).toContain("Celeste, il y a 2 h");
  });

  it("says when no friend played lately, and points to the directory without a friend", () => {
    expect(render({ hasFriends: true, playing: [], recent: [] })).toContain("ces dernières 24 h");
    expect(render({ hasFriends: false, playing: [], recent: [] })).toContain('href="/joueurs"');
  });

  it("shows nothing to a visitor", () => {
    mockUser = null;
    expect(render({ hasFriends: true, playing: [playing("alice")], recent: [] })).toBe("");
  });
});
