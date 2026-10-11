import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { FriendCard } from "./community-friends-api";
import { FRIEND_GROUPS_KEY, type FriendGroup } from "./friend-groups-api";
import { FriendGroupsPanel } from "./friend-groups-panel";
import { groupPick } from "@/features/personal-runs/run-invitations";
import type { RunInvitation } from "@/features/personal-runs/run-invitations-api";

jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: () => undefined }),
}));

function card(slug: string): FriendCard {
  return { userId: `u-${slug}`, slug, displayName: slug.charAt(0).toUpperCase() + slug.slice(1), avatarUrl: null };
}

const thursday: FriendGroup = { id: "g1", name: "La team du jeudi", memberIds: ["u-alice", "u-bob", "u-carol", "u-gone"] };

function render(groups: FriendGroup[], friends: FriendCard[]): string {
  const client = new QueryClient();
  client.setQueryData(FRIEND_GROUPS_KEY, groups);
  return renderToStaticMarkup(
    <QueryClientProvider client={client}>
      <FriendGroupsPanel friends={friends} />
    </QueryClientProvider>,
  );
}

/** Story 43.13: private groups of friends. */
describe("friend groups", () => {
  it("shows each group with its friends and those that can still be added", () => {
    const html = render([thursday], [card("alice"), card("bob"), card("dave")]);

    expect(html).toContain("Mes groupes");
    expect(html).toContain("La team du jeudi");
    expect(html).toContain("Retirer Alice du groupe");
    expect(html).toContain("Retirer Bob du groupe");
    expect(html).toContain('<option value="u-dave">Dave</option>');
    expect(html).not.toContain('<option value="u-alice">');
  });

  it("checks a whole group in the invitation, leaving out who is in, invited or no longer a friend", () => {
    const friends = [card("alice"), card("bob"), card("carol")];
    const invited: RunInvitation = { invitationId: "i1", status: "pending", invitedAt: "2026-10-10T10:00:00Z", respondedAt: null, invitee: card("bob") };

    expect(groupPick(thursday, friends, new Set(["u-carol"]), [invited])).toEqual({ pick: ["u-alice"], skipped: 3 });
    expect(groupPick({ ...thursday, memberIds: ["u-alice"] }, friends, new Set(), [])).toEqual({ pick: ["u-alice"], skipped: 0 });
  });
});
