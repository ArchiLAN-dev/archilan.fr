import { renderToStaticMarkup } from "react-dom/server";

import { GenerationFailureNotice } from "./generation-failure-notice";

/** Story 38.16: one failed generation, the same way everywhere. */
describe("GenerationFailureNotice", () => {
  it("names the kind, the slot, the message and what the player can do", () => {
    const html = renderToStaticMarkup(
      <GenerationFailureNotice audience="player" message="La génération a échoué à cause de ce slot : Fill.FillError: boom" slotName="Jean" />,
    );

    expect(html).toContain("Remplissage impossible");
    expect(html).toContain("Slot « Jean »");
    expect(html).toContain("Fill.FillError: boom");
    expect(html).not.toContain("à cause de ce slot");
    expect(html).toMatch(/assouplis les options/);
    expect(html).not.toContain("Journal technique");
  });

  it("folds the raw log under a copy button for the admin", () => {
    const html = renderToStaticMarkup(<GenerationFailureNotice audience="admin" log={"Traceback...\nFillError"} message="" />);

    expect(html).toContain("Erreur de génération");
    expect(html).toContain("Journal technique");
    expect(html).toContain("Copier");
  });
});
