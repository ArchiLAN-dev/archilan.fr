import { renderToStaticMarkup } from "react-dom/server";

import { SanctionForm } from "./sanction-dialog";

const noop = () => undefined;
const submit = () => Promise.resolve(null);

function render(command: Parameters<typeof SanctionForm>[0]["initialCommand"]): string {
  return renderToStaticMarkup(<SanctionForm initialCommand={command} name="Kafei" onCancel={noop} onSubmit={submit} />);
}

/**
 * Story 39.11. One window for every sanction, from the reports tab and from the admin sheet alike:
 * the action is picked in it, and what it asks for follows the action.
 */
describe("SanctionForm", () => {
  test("offers every action, the chosen one checked", () => {
    const html = render("warn");

    for (const label of ["Avertir", "Suspendre", "Bannir", "Lever", "Note interne"]) {
      expect(html).toContain(label);
    }
    expect(html).toMatch(/value="warn"[^>]*checked|checked[^>]*value="warn"/);
    expect(html).toContain("Motif (obligatoire)");
  });

  test("asks for an end date only for a suspension", () => {
    expect(render("suspend")).toContain('type="datetime-local"');
    expect(render("warn")).not.toContain('type="datetime-local"');
    expect(render("ban")).not.toContain('type="datetime-local"');
  });

  test("a ban is confirmed by a red button that says so", () => {
    const html = render("ban");

    expect(html).toMatch(/<button[^>]*bg-danger[^>]*>[^<]*Bannir/);
  });

  test("an internal note says the member is not told", () => {
    const html = render("note");

    expect(html).toContain("Note (obligatoire)");
    expect(html).toContain("le membre n&#x27;est pas prévenu");
    expect(html).toContain("Enregistrer la note");
  });

  test("the submit button waits for a reason", () => {
    expect(render("warn")).toMatch(/<button[^>]*disabled=""[^>]*type="submit"|<button[^>]*type="submit"[^>]*disabled=""/);
  });
});
