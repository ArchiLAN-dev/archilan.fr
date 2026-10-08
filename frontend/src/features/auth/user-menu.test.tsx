import { renderToStaticMarkup } from "react-dom/server";

import type { AuthUser } from "./auth-context";
import { AccountMenuLinks } from "./user-menu";

const member: AuthUser = {
  id: "u1",
  email: "membre@example.com",
  slug: "membre",
  displayName: "Membre",
  roles: ["ROLE_USER"],
  emailVerifiedAt: "2026-01-01T00:00:00Z",
  steamProfile: null,
};

function hrefs(user: AuthUser): string[] {
  const html = renderToStaticMarkup(<AccountMenuLinks gold={null} onNavigate={() => undefined} user={user} />);
  return [...html.matchAll(/href="([^"]+)"/g)].map((match) => match[1]);
}

describe("AccountMenuLinks", () => {
  test("offers « Mes parties » first (story 16.22)", () => {
    const html = renderToStaticMarkup(<AccountMenuLinks gold={null} onNavigate={() => undefined} user={member} />);

    expect(html).toContain("Mes parties");
    expect(hrefs(member)).toEqual(["/compte/parties", "/joueurs/membre", "/compte", "/compte/portefeuille"]);
  });

  test("keeps it for a member without a public profile, and for an admin", () => {
    expect(hrefs({ ...member, slug: null })).toEqual(["/compte/parties", "/compte", "/compte/portefeuille"]);
    expect(hrefs({ ...member, roles: ["ROLE_USER", "ROLE_ADMIN"] })).toEqual([
      "/compte/parties",
      "/joueurs/membre",
      "/compte",
      "/compte/portefeuille",
      "/admin",
    ]);
  });
});
