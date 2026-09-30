import { renderToStaticMarkup } from "react-dom/server";

import type { ApworldCandidate } from "./admin-games-api";
import { ApworldCandidateStatus } from "./apworld-candidate-status";

// The real ConfirmDialog renders into a portal, absent from static markup: a stand-in shows its props.
jest.mock("../../components/ui/confirm-dialog", () => ({
  ConfirmDialog: ({ open, title, description, confirmLabel, tone }: { open: boolean; title: string; description: React.ReactNode; confirmLabel: string; tone?: string }) => (
    <div data-confirm="" data-open={String(open)} data-tone={tone ?? "default"}>
      {title}|{description}|{confirmLabel}
    </div>
  ),
}));

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

  test("an expired candidate says its test gave no verdict and will be tried again (story 38.6 review)", () => {
    const html = render(
      candidate({ status: "expired", decidedAt: "2026-09-26T04:45:00+02:00", rejectionReason: "Le test de génération n'a pas rendu de verdict dans le délai de 30 minutes : il sera retenté." }),
    );

    expect(html).toContain("Test sans verdict");
    expect(html).toContain("retenté");
    expect(html).toContain("Relancer le test");
    expect(html).not.toContain("rejetée");
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

  test("forcing asks for confirmation in a modal, in the danger tone (story 38.13)", () => {
    const html = render(candidate());

    expect(html).toMatch(/data-confirm="" data-open="false" data-tone="danger">Mettre CrystalProject-v0.18.2 en service/);
    expect(html).toContain("|Forcer la mise en service");
    expect(html).not.toContain('role="dialog"');
  });
});
