import { renderToStaticMarkup } from "react-dom/server";

import { IdleBanner } from "./idle-banner";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

const base = {
  lastActivityAt: "2026-08-14T10:23:58.000Z",
  busy: false,
  onResume: () => undefined,
};

/**
 * Story 16.15. The two variants of a sleeping run used to be indistinguishable: same neutral card,
 * same primary button, and "Relancer depuis le début" fired straight away even though it wipes
 * every participant's progress.
 */
describe("IdleBanner", () => {
  test("with a save, resuming is one click and says what will happen", () => {
    const html = render(<IdleBanner {...base} pausedWithoutSave={false} />);

    expect(html).toContain("Partie en veille");
    expect(html).toContain("la dernière sauvegarde est rechargée automatiquement");
    expect(html).toContain("Reprendre");
    // No confirmation on the harmless path: teaching the reflex of confirming without reading is
    // exactly what would defeat the dialog on the destructive one.
    expect(html).not.toContain('aria-haspopup="dialog"');
    expect(html).not.toContain("Relancer depuis le début");
  });

  test("the wording of the architecture stays out of the interface", () => {
    const html = render(<IdleBanner {...base} pausedWithoutSave={false} />);

    // "Reprendre manuellement" described a decision (no wake-on-connect) the player has no use for.
    expect(html).not.toContain("manuellement");
  });

  test("without a save, the destructive path names its cost and asks first", () => {
    const html = render(<IdleBanner {...base} pausedWithoutSave />);

    expect(html).toContain("Aucune sauvegarde disponible");
    expect(html).toContain("la progression de tous les participants sera perdue");
    expect(html).toContain("Relancer depuis le début");
    expect(html).toContain('aria-haspopup="dialog"');
  });

  test("the two variants do not share a register", () => {
    const safe = render(<IdleBanner {...base} pausedWithoutSave={false} />);
    const destructive = render(<IdleBanner {...base} pausedWithoutSave />);

    // --color-warning and --color-accent-warm hold the same value, so the tint alone would not
    // separate them: the destructive button carries the danger colour the page uses elsewhere for
    // irreversible actions.
    expect(destructive).toContain("var(--color-danger)");
    expect(safe).not.toContain("var(--color-danger)");
  });

  test("a busy action disables the button rather than letting it be fired twice", () => {
    expect(render(<IdleBanner {...base} busy pausedWithoutSave={false} />)).toContain("disabled");
    expect(render(<IdleBanner {...base} busy pausedWithoutSave />)).toContain("disabled");
  });

  test("a run with no recorded activity still offers its action", () => {
    const html = render(<IdleBanner {...base} lastActivityAt={null} pausedWithoutSave={false} />);

    expect(html).toContain("Partie en veille");
    expect(html).toContain("Reprendre");
  });
});
