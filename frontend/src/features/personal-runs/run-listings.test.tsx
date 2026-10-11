import { renderToStaticMarkup } from "react-dom/server";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

import type { AuthUser } from "../auth/auth-context";
import type { FriendCard } from "@/features/community/community-friends-api";
import { RunOpennessSetting } from "./friends-open-runs";
import { RUN_LISTINGS_KEY, type RunListing } from "./run-listings-api";
import { friendsInLabel, plannedLabel, RunListingsBoard, RunListingsSpotlight } from "./run-listings";

let mockUser: Partial<AuthUser> | null = { id: "me" };
jest.mock("../auth/auth-context", () => ({
  useAuth: () => ({ user: mockUser, loading: false, setUser: () => undefined }),
}));
jest.mock("next/navigation", () => ({
  useRouter: () => ({ push: () => undefined }),
}));

function card(slug: string): FriendCard {
  return { userId: `u-${slug}`, slug, displayName: slug.charAt(0).toUpperCase() + slug.slice(1), avatarUrl: null };
}

function render(node: React.ReactNode, listings: RunListing[] | undefined = undefined): string {
  const client = new QueryClient();
  if (listings !== undefined) client.setQueryData(RUN_LISTINGS_KEY, listings);
  return renderToStaticMarkup(<QueryClientProvider client={client}>{node}</QueryClientProvider>);
}

const listing: RunListing = {
  runId: "r1",
  title: "La giga async",
  pitch: "On cherche deux joueurs, rythme libre.",
  plannedFor: null,
  listedAt: "2026-10-10T10:00:00Z",
  seatsWanted: 4,
  joined: 2,
  games: ["Hollow Knight", "Celeste"],
  owner: card("alice"),
  isOwnerFriend: false,
  friendsIn: [card("bob")],
};

/** Story 43.17: « Parties qui cherchent des joueurs ». */
describe("run listings", () => {
  it("says who is already in and when the run is planned", () => {
    expect(friendsInLabel([])).toBeNull();
    expect(friendsInLabel([card("bob")])).toBe("Bob y est déjà");
    expect(friendsInLabel([card("bob"), card("carol"), card("dave")])).toBe("Bob, Carol et Dave y sont déjà");
    expect(plannedLabel(null)).toBeNull();
    expect(plannedLabel("2026-11-02T19:00:00Z")).toContain("Prévue le");
  });

  it("lists each listing with its games, seats and friends, to join or report", () => {
    mockUser = { id: "me" };
    const html = render(<RunListingsBoard />, [listing]);

    expect(html).toContain("« La giga async »");
    expect(html).toContain("On cherche deux joueurs, rythme libre.");
    expect(html).toContain("Jeux : Hollow Knight, Celeste");
    expect(html).toContain("2 / 4 places");
    expect(html).toContain("Bob y est déjà");
    expect(html).toContain("Rejoindre");
    expect(html).toContain("Signaler");
  });

  it("asks a visitor to sign in", () => {
    mockUser = null;
    expect(render(<RunListingsBoard />)).toContain("Connecte-toi");
    mockUser = { id: "me" };
  });

  it("offers the owner a listing for every member, with its message", () => {
    const html = render(<RunOpennessSetting openness="members" pitch="Venez !" plannedFor={null} runId="r1" seatsWanted={2} />);

    expect(html).toContain("Tous les membres");
    expect(html).toContain("Annonce en ligne");
    expect(html).toContain("Venez !");
    expect(html).toContain("Places voulues");
    expect(html).toContain("Date prévue");
  });
});

/** Story 43.21: the listings put forward. */
describe("run listings spotlight", () => {
  const more = [listing, { ...listing, runId: "r2", title: "Seconde" }, { ...listing, runId: "r3", title: "Troisième" }];

  it("shows the two latest listings on « Communauté » and the way to all of them", () => {
    mockUser = { id: "me" };
    const html = render(<RunListingsSpotlight />, more);
    expect(html).toContain("Parties qui cherchent des joueurs");
    expect(html).toContain("« La giga async »");
    expect(html).toContain("« Seconde »");
    expect(html).not.toContain("Troisième");
    expect(html).toContain("Voir les 3 annonces");
    expect(html).toContain('href="/communaute/parties"');
  });

  it("invites to publish one when there is none, and a visitor to sign in", () => {
    expect(render(<RunListingsSpotlight />, [])).toContain("Publie la tienne");
    mockUser = null;
    expect(render(<RunListingsSpotlight />)).toContain("Connecte-toi");
    mockUser = { id: "me" };
  });
});
