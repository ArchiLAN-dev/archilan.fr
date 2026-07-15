import robots from "./robots";

describe("robots", () => {
  it("allows the public site and points at the absolute sitemap URL", () => {
    const result = robots();

    expect(result.rules).toEqual(
      expect.objectContaining({ userAgent: "*", allow: "/" }),
    );
    expect(result.sitemap).toBe("http://localhost:3000/sitemap.xml");
  });

  it("disallows exactly the crawl-worthless private zones", () => {
    const rules = robots().rules;
    const disallow = Array.isArray(rules) ? [] : rules.disallow;

    expect(disallow).toEqual([
      "/admin/",
      "/o/",
      "/compte/",
      "/connexion",
      "/inscription",
      "/mot-de-passe-oublie",
      "/reinitialisation-mot-de-passe",
      "/confirmation-email",
      "/runs/",
      "/evenements/*/inscription",
    ]);
  });

  it("does NOT disallow meta-noindexed but externally-linkable zones", () => {
    const rules = robots().rules;
    const disallow = Array.isArray(rules) ? [] : rules.disallow;
    const joined = (Array.isArray(disallow) ? disallow : [disallow]).join(" ");

    // These carry meta noindex; disallowing them would stop Google seeing that tag.
    expect(joined).not.toContain("/joueurs");
    expect(joined).not.toContain("/streams");
    expect(joined).not.toContain("resultats");
  });
});
