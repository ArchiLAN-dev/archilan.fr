import { renderToStaticMarkup } from "react-dom/server";

import { AdminNoteMarker } from "./admin-game-library-dashboard";

/** Story 11.6: the admin list marks the games that carry an internal note. */
describe("AdminNoteMarker", () => {
  test("nothing without a note", () => {
    expect(renderToStaticMarkup(<AdminNoteMarker game={{ hasAdminNotes: false, adminNotesExcerpt: null }} />)).toBe("");
    expect(renderToStaticMarkup(<AdminNoteMarker game={{}} />)).toBe("");
  });

  test("a labelled icon whose hover shows the note's beginning", () => {
    const html = renderToStaticMarkup(<AdminNoteMarker game={{ hasAdminNotes: true, adminNotesExcerpt: "Utiliser la version patchée" }} />);

    expect(html).toContain('aria-label="Note interne"');
    expect(html).toContain('title="Note interne : Utiliser la version patchée"');
    expect(html).toContain("<svg");
  });
});
