import { renderToStaticMarkup } from "react-dom/server";

import { ContributionDetail, changeSummary } from "./contribution-detail-page";
import { ContributionRow, contributionHref } from "./contributions-moderation-panel";
import type { ContributionItem } from "./admin-game-contributions-api";

const step = (title: string, description = "") => ({ type: "apworld" as const, title, description });

const item: ContributionItem = {
  id: "c1",
  status: "pending",
  createdAt: "2026-10-05T10:00:00+00:00",
  authorName: "Alice",
  message: "J'ai précisé l'installation.",
  target: "Hollow Knight",
  gameSlug: "hollow-knight",
  gameId: "g1",
  reviewedAt: null,
  rejectionReason: null,
  proposedSteps: [step("Installer"), step("Configurer", "plus clair"), step("Jouer")],
  currentSteps: [step("Installer"), step("Configurer")],
};

const noop = async () => undefined;

/** Story 39.16: the contributions queue is a list, each contribution has its own page. */
describe("contributions list and detail", () => {
  test("a row links to the contribution's page, keeping the list's view", () => {
    expect(contributionHref("c1", "")).toBe("/admin/moderation/contributions/c1");
    expect(contributionHref("c1", "status=all&q=hollow")).toBe("/admin/moderation/contributions/c1?liste=status%3Dall%26q%3Dhollow");

    const html = renderToStaticMarkup(<ContributionRow item={{ ...item, gameSlug: null }} listQuery="" />);
    expect(html).toContain("Hollow Knight");
    expect(html).toContain("Jeu non listé");
    expect(html).toContain("3 étapes proposées");
    expect(html).toContain("En attente");
  });

  test("the summary names the changes only, or the size of a whole new tutorial", () => {
    expect(changeSummary({ same: 1, modified: 2, added: 1, removed: 0 }, true)).toBe("2 modifiées, 1 ajoutée");
    expect(changeSummary({ same: 3, modified: 0, added: 0, removed: 0 }, true)).toBe("Aucune différence avec le tutoriel actuel");
    expect(changeSummary({ same: 0, modified: 0, added: 4, removed: 0 }, false)).toBe("4 étapes");
  });

  test("a pending contribution shows its comparison, its editor link and its decisions", () => {
    const html = renderToStaticMarkup(<ContributionDetail item={item} onDecided={noop} />);

    expect(html).toContain("1 modifiée, 1 ajoutée");
    expect(html).toContain("Identique");
    expect(html).toContain("plus clair");
    expect(html).toContain('href="/admin/jeux/g1"');
    expect(html).toContain("Approuver");
    expect(html).toContain("Rejeter");
  });

  test("a decided contribution shows its decision instead of the actions", () => {
    const html = renderToStaticMarkup(
      <ContributionDetail item={{ ...item, status: "rejected", reviewedAt: "2026-10-05T12:00:00+00:00", rejectionReason: "Doublon" }} onDecided={noop} />,
    );

    expect(html).toContain("Rejetée le");
    expect(html).toContain("« Doublon »");
    expect(html).not.toContain(">Approuver<");
    // Decided: the game's tutorial may have moved on, so the proposal is shown alone.
    expect(html).toContain("3 étapes");
    expect(html).not.toContain("Identique");
  });
});
