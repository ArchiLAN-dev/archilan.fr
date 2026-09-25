import { renderToStaticMarkup } from "react-dom/server";

import type { ApworldCandidate } from "./admin-games-api";
import { ApworldCandidateStatus } from "./apworld-candidate-status";

const noop = () => undefined;

function candidate(overrides: Partial<ApworldCandidate> = {}): ApworldCandidate {
  return {
    id: "candidate-1",
    status: "testing",
    apworldHash: "3bf11e98093174eb2439df10e13caac0836a0bbb3f9ba53a91b42800081f97d6",
    versionTag: "CrystalProject-v0.18.2",
    origin: "manual",
    submittedAt: "2026-09-26T04:10:00+02:00",
    decidedAt: null,
    rejectionReason: null,
    ...overrides,
  };
}

function render(c: ApworldCandidate | null, busy = false): string {
  return renderToStaticMarkup(<ApworldCandidateStatus busy={busy} candidate={c} onForce={noop} onRetry={noop} />);
}

/** Story 38.6: the game page says a new version is waiting for its test, or was refused by it. */
describe("ApworldCandidateStatus", () => {
  test("a candidate in test says the game still serves its current apworld", () => {
    const html = render(candidate());

    expect(html).toContain("Nouvelle version en test");
    expect(html).toContain("CrystalProject-v0.18.2");
    expect(html).toContain("Import manuel");
    expect(html).toContain("le jeu sert toujours sa version actuelle");
    expect(html).toContain("Forcer");
    expect(html).not.toContain("Relancer le test");
  });

  test("an automatic candidate says where it comes from", () => {
    expect(render(candidate({ origin: "auto" }))).toContain("Mise à jour automatique");
  });

  test("without a tag the short hash names the version", () => {
    expect(render(candidate({ versionTag: null }))).toContain("3bf11e98093174eb");
  });

  test("a rejected candidate shows why, and offers to retry or to force it", () => {
    const html = render(candidate({ status: "rejected", decidedAt: "2026-09-26T04:20:00+02:00", rejectionReason: "Fill.FillError: Could not access required locations" }));

    expect(html).toContain("Nouvelle version rejetée");
    expect(html).toContain("Fill.FillError: Could not access required locations");
    expect(html).toContain("Relancer le test");
    expect(html).toContain("Forcer quand même");
  });

  test("forcing asks for confirmation first", () => {
    expect(render(candidate())).toContain('aria-haspopup="dialog"');
  });

  test("the buttons are disabled while an action runs", () => {
    expect(render(candidate({ status: "rejected", rejectionReason: "boom" }), true)).toMatch(/<button[^>]*disabled=""[^>]*>(?:(?!<\/button>)[\s\S])*Relancer le test/);
  });

  test("nothing is shown without a candidate", () => {
    expect(render(null)).toBe("");
  });
});
