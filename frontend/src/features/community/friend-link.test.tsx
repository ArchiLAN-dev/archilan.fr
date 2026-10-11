import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { FriendLinkResult } from "./community-friends-api";
import { FriendLinkPage, MY_FRIEND_LINK_KEY, MyFriendLink, friendLinkStatus, friendLinkUrl, qrPath } from "./friend-link";

function render(node: React.ReactNode, seed: (client: QueryClient) => void): string {
  const client = new QueryClient();
  seed(client);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

describe("friend link (story 43.3)", () => {
  it("draws a QR code as square modules, the bigger the longer the text", () => {
    const short = qrPath("https://archilan.fr/ami/abcdefghjkmn");
    expect(short.size).toBeGreaterThanOrEqual(21);
    expect((short.size - 17) % 4).toBe(0);
    expect(short.d).toMatch(/^(M\d+ \d+h1v1h-1z)+$/);
    // The three finder patterns: the top-left corner is always dark.
    expect(short.d.startsWith("M0 0h1v1h-1z")).toBe(true);

    expect(qrPath("x".repeat(200)).size).toBeGreaterThan(short.size);
  });

  it("shows my link and its QR code", () => {
    const html = render(<MyFriendLink />, (client) => client.setQueryData(MY_FRIEND_LINK_KEY, "abcdefghjkmn"));

    expect(html).toContain("Mon lien d&#x27;ami");
    expect(html).toContain(friendLinkUrl("abcdefghjkmn"));
    expect(html).toContain("QR code de mon lien d&#x27;ami");
    expect(html).toContain("Plein écran");
    expect(html).toContain("Régénérer");
  });

  it("offers to add the member behind a link", () => {
    const result: FriendLinkResult = {
      kind: "ok",
      view: { member: { userId: "u-1", slug: "alice", displayName: "Alice", avatarUrl: null }, relationship: { state: "none", friendshipId: null } },
    };
    const html = render(<FriendLinkPage code="abc" />, (client) => client.setQueryData(["community-friend-link-view", "abc"], result));

    expect(html).toContain("Alice");
    expect(html).toContain("Ajouter en ami");
  });

  it("says the same thing for any invalid link", () => {
    const html = render(<FriendLinkPage code="nope" />, (client) =>
      client.setQueryData(["community-friend-link-view", "nope"], { kind: "invalid" } satisfies FriendLinkResult),
    );

    expect(html).toContain("Lien d&#x27;ami invalide");
  });

  it("explains why there is no button", () => {
    expect(friendLinkStatus("none")).toBeNull();
    expect(friendLinkStatus("incoming")).toBeNull();
    expect(friendLinkStatus("self")).toContain("ton propre lien");
    expect(friendLinkStatus("friends")).toBe("Vous êtes déjà amis.");
    expect(friendLinkStatus("blocked")).toBe(friendLinkStatus("blocking"));
  });
});
