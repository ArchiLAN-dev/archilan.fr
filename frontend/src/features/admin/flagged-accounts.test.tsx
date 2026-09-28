import { renderToStaticMarkup } from "react-dom/server";

import type { FlaggedAccount } from "./admin-moderation-api";
import { FlaggedAccounts } from "./flagged-accounts";

function account(overrides: Partial<FlaggedAccount> = {}): FlaggedAccount {
  return {
    userId: "u1",
    slug: "kafei",
    displayName: "Kafei",
    avatarUrl: null,
    score: 12,
    reportCount: 3,
    ...overrides,
  };
}

function render(accounts: FlaggedAccount[]): string {
  return renderToStaticMarkup(<FlaggedAccounts accounts={accounts} onActed={() => undefined} threshold={10} />);
}

/**
 * Story 39.11. The accounts over the threshold are one flat list: a row per account, and the sanction
 * or the history open in their own window instead of unfolding inside the row.
 */
describe("FlaggedAccounts", () => {
  test("a row says who, how bad, and offers to sanction or look at the history", () => {
    const html = render([account(), account({ userId: "u2", slug: "link", displayName: "Link", reportCount: 1 })]);

    expect(html).toContain("comptes au-delà du seuil (10)");
    expect(html).toContain('href="/joueurs/kafei"');
    expect(html).toContain("3 signalements");
    expect(html).toContain("1 signalement");
    expect(html.match(/Sanctionner/g)).toHaveLength(2);
    expect(html.match(/Historique/g)).toHaveLength(2);
  });

  test("the rows are separated by a line, not boxed inside the section", () => {
    const html = render([account(), account({ userId: "u2" })]);

    expect(html).toContain("divide-y");
    expect(html).not.toMatch(/<li[^>]*border/);
    // The sanction form no longer unfolds in the row.
    expect(html).not.toContain("<textarea");
  });

  test("a deleted account can still be sanctioned", () => {
    const html = render([account({ slug: null, displayName: null })]);

    expect(html).toContain("Compte supprimé");
    expect(html).toContain("Sanctionner");
  });
});
