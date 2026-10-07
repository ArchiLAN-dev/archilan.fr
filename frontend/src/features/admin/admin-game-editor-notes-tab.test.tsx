import { renderToStaticMarkup } from "react-dom/server";

import { EditorTabButton, PendingTutorialProposals, apworldNeedsAttention, editorTabFlags, hasAdminNote } from "./admin-game-editor";
import type { ContributionItem } from "./admin-game-contributions-api";

type FlagGame = Parameters<typeof editorTabFlags>[0];

const quiet: FlagGame = { adminNotes: null, apworldCandidate: null, apworldPreflight: null };

/** Story 11.7: the editor's « Notes », « APWorld » and « Tutoriel » tabs stand out when something waits there. */
describe("game editor tab flags", () => {
  test("a note is any non-blank text", () => {
    expect(hasAdminNote("Ne pas activer le DLC.")).toBe(true);
    expect(hasAdminNote("   \n ")).toBe(false);
    expect(hasAdminNote(null)).toBe(false);
  });

  test("a quiet game flags nothing", () => {
    expect(Object.values(editorTabFlags(quiet, 0, 0)).every((flag) => flag === null)).toBe(true);
  });

  test("the apworld needs the admin on an open incident, a version awaiting them, or an unwaived failed test", () => {
    const candidate = { id: "c", status: "awaiting", apworldHash: "h", versionTag: null, origin: "auto", submittedAt: "", decidedAt: null, rejectionReason: null } as const;
    const failed = { status: "failed", error: "boom", checkedAt: "", overridden: false, blocks: true } as const;

    expect(apworldNeedsAttention(quiet, 1)).toBe(true);
    expect(apworldNeedsAttention({ ...quiet, apworldCandidate: candidate }, 0)).toBe(true);
    expect(apworldNeedsAttention({ ...quiet, apworldCandidate: { ...candidate, status: "testing" } }, 0)).toBe(false);
    expect(apworldNeedsAttention({ ...quiet, apworldPreflight: failed }, 0)).toBe(true);
    expect(apworldNeedsAttention({ ...quiet, apworldPreflight: { ...failed, overridden: true } }, 0)).toBe(false);
  });

  test("the tutorial tab counts the proposals waiting, the notes tab says a note exists", () => {
    const flags = editorTabFlags({ ...quiet, adminNotes: "Lire avant" }, 0, 3);

    expect(flags.tutoriel?.hint).toBe("3 propositions en attente");
    expect(flags.tutoriel?.count).toBe(3);
    expect(flags.notes?.hint).toBe("une note interne existe");
    expect(editorTabFlags(quiet, 0, 1).tutoriel?.hint).toBe("une proposition en attente");
  });

  test("a flagged tab shows its icon, the warm colour, a count for several, and says why", () => {
    const flags = editorTabFlags(quiet, 0, 3);
    const html = renderToStaticMarkup(<EditorTabButton active={false} flag={flags.tutoriel} id="tutoriel" label="Tutoriel" onSelect={() => {}} />);

    expect(html).toContain('aria-label="Tutoriel - 3 propositions en attente"');
    expect(html).toContain("text-accent-warm");
    expect(html).toContain("<svg");
    expect(html).toMatch(/>3<\/span>/);
  });

  test("an unflagged tab looks like the others", () => {
    const html = renderToStaticMarkup(<EditorTabButton active={false} flag={null} id="notes" label="Notes" onSelect={() => {}} />);

    expect(html).not.toContain("accent-warm");
    expect(html).not.toContain("aria-label");
    expect(html).not.toContain("<svg");
  });

  test("the tutorial tab lists the proposals and opens the moderation queue on this game", () => {
    const proposal: ContributionItem = {
      id: "p1",
      status: "pending",
      createdAt: "2026-10-04T10:00:00+00:00",
      authorName: "Alice",
      message: "Il manque l'étape du patch",
      target: "listed",
      gameSlug: "zelda",
      proposedSteps: [],
      currentSteps: [],
    };
    const html = renderToStaticMarkup(<PendingTutorialProposals gameName="Zelda" proposals={[proposal]} />);

    expect(html).toContain("Une proposition de tutoriel en attente");
    expect(html).toContain("Alice");
    expect(html).toContain("Il manque l&#x27;étape du patch");
    expect(html).toContain('href="/admin/moderation/contributions?q=Zelda"');
    expect(renderToStaticMarkup(<PendingTutorialProposals gameName="Zelda" proposals={[]} />)).toBe("");
  });
});
