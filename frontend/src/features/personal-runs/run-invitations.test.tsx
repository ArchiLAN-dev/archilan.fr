import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { FriendCard } from "@/features/community/community-friends-api";
import type { ReceivedRunInvitation, RunInvitation } from "./run-invitations-api";
import { MY_RUN_INVITATIONS_KEY, MyRunInvitations, matchesSearch, unavailableReason } from "./run-invitations";

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: () => undefined }),
}));

function card(slug: string, displayName: string | null = null): FriendCard {
  return { userId: `u-${slug}`, slug, displayName, avatarUrl: null };
}

function invitation(invitee: FriendCard, status: RunInvitation["status"]): RunInvitation {
  return { invitationId: `i-${invitee.slug}`, status, invitedAt: "2026-10-09T12:00:00Z", respondedAt: null, invitee };
}

describe("run invitations (story 43.1)", () => {
  it("says why a friend cannot be picked", () => {
    const participants = new Set(["u-in"]);
    const invitations = [invitation(card("pending"), "pending"), invitation(card("joined"), "accepted"), invitation(card("declined"), "declined")];

    expect(unavailableReason(card("in"), participants, invitations)).toBe("Participe déjà");
    expect(unavailableReason(card("pending"), participants, invitations)).toBe("Invité");
    expect(unavailableReason(card("joined"), participants, invitations)).toBe("A rejoint");
    expect(unavailableReason(card("declined"), participants, invitations)).toBeNull();
    expect(unavailableReason(card("free"), participants, invitations)).toBeNull();
  });

  it("finds a friend by pseudo or slug", () => {
    expect(matchesSearch(card("masterkafey", "Jean"), "jea")).toBe(true);
    expect(matchesSearch(card("masterkafey", "Jean"), "KAFEY")).toBe(true);
    expect(matchesSearch(card("masterkafey", "Jean"), "alice")).toBe(false);
    expect(matchesSearch(card("masterkafey", "Jean"), "  ")).toBe(true);
  });

  it("lists the invitations received, to join or decline", () => {
    const client = new QueryClient();
    const received: ReceivedRunInvitation[] = [
      { invitationId: "i-1", runId: "run-1", runTitle: "La giga async", runStatus: "draft", invitedAt: "2026-10-09T12:00:00Z", inviter: card("alice", "Alice") },
    ];
    client.setQueryData(MY_RUN_INVITATIONS_KEY, received);

    const html = renderToStaticMarkup(
      <QueryClientProvider client={client}>
        <MyRunInvitations />
      </QueryClientProvider>,
    );

    expect(html).toContain("Alice");
    expect(html).toContain("« La giga async »");
    expect(html).toContain("Rejoindre");
    expect(html).toContain("Refuser l&#x27;invitation dans « La giga async »");
  });

  it("shows nothing without an invitation", () => {
    const client = new QueryClient();
    client.setQueryData(MY_RUN_INVITATIONS_KEY, []);

    expect(
      renderToStaticMarkup(
        <QueryClientProvider client={client}>
          <MyRunInvitations />
        </QueryClientProvider>,
      ),
    ).toBe("");
  });
});
