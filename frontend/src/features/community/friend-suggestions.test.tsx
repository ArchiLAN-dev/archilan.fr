import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { FriendSuggestion } from "./community-friends-api";
import { FriendSuggestions, togetherLine } from "./friend-suggestions";

let mockUser: Partial<AuthUser> | null = null;
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));

function suggestion(slug: string, extra: Partial<FriendSuggestion> = {}): FriendSuggestion {
  return {
    userId: `u-${slug}`,
    slug,
    displayName: slug,
    avatarUrl: null,
    sessionsTogether: 1,
    lastTitle: null,
    lastPlayedAt: null,
    ...extra,
  };
}

function render(data: FriendSuggestion[] | null, sessionId?: string): string {
  const client = new QueryClient();
  client.setQueryData(["community-friend-suggestions", sessionId ?? null, 3], data);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <FriendSuggestions limit={3} sessionId={sessionId} title="Ajoute tes co-joueurs" />
    </QueryClientProvider>,
  );
}

describe("FriendSuggestions (story 43.2)", () => {
  afterEach(() => {
    mockUser = null;
  });

  it("shows the members played with, to add or ignore", () => {
    mockUser = { slug: "me" };

    const html = render([suggestion("alice", { sessionsTogether: 3, lastTitle: "La giga async" })], "s-1");

    expect(html).toContain("Ajoute tes co-joueurs");
    expect(html).toContain("alice");
    expect(html).toContain("3 parties ensemble · La giga async");
    expect(html).toContain("Ajouter alice en ami");
    expect(html).toContain("Ignorer alice");
  });

  it("renders nothing for a visitor", () => {
    expect(render([suggestion("alice")], "s-1")).toBe("");
  });

  it("renders nothing when there is nobody to suggest", () => {
    mockUser = { slug: "me" };

    expect(render([], "s-1")).toBe("");
    expect(render(null, "s-1")).toBe("");
  });

  it("agrees with a single game", () => {
    expect(togetherLine(suggestion("bob"))).toBe("1 partie ensemble");
  });
});
