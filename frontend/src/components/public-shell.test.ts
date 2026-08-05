import { isActive } from "./public-shell";

describe("isActive", () => {
  it("matches the entry's own route and its children", () => {
    expect(isActive("/communaute", "/communaute")).toBe(true);
    expect(isActive("/evenements/abc", "/evenements")).toBe(true);
  });

  it("does not match a route that merely shares a prefix", () => {
    expect(isActive("/runs-hebdo", "/runs")).toBe(false);
  });

  it("lights Communauté on the members directory and player profiles", () => {
    // /joueurs is part of the Communauté section without sitting under /communaute - without the
    // alsoMatch list the whole nav bar goes unlit there.
    expect(isActive("/joueurs", "/communaute", ["/joueurs"])).toBe(true);
    expect(isActive("/joueurs/yougoxes", "/communaute", ["/joueurs"])).toBe(true);
    expect(isActive("/joueurs/yougoxes/succes", "/communaute", ["/joueurs"])).toBe(true);
  });

  it("leaves other sections alone", () => {
    expect(isActive("/jeux", "/communaute", ["/joueurs"])).toBe(false);
  });
});
