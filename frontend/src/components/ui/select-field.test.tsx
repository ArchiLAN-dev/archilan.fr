import { renderToStaticMarkup } from "react-dom/server";

import { SelectField } from "./select-field";

const OPTIONS = [
  { value: "any", label: "Tous" },
  { value: "profile", label: "Profils" },
];

function render(value: string, highlighted = false): string {
  return renderToStaticMarkup(<SelectField highlighted={highlighted} label="Cible" onChange={() => undefined} options={OPTIONS} value={value} />);
}

/**
 * A dropdown drawn by the site rather than the operating system (story 39.13): its opened list follows the
 * theme, which a native select does not on Windows. Closed, it reads "Cible  Profils ▾".
 */
describe("SelectField", () => {
  test("names itself in the control and shows the chosen label", () => {
    const html = render("profile");

    expect(html).toContain(">Cible<");
    expect(html).toContain(">Profils<");
    expect(html).toContain('role="combobox"');
    expect(html).toContain('aria-label="Cible : Profils"');
  });

  test("is not the browser's native select", () => {
    expect(render("any")).not.toMatch(/<select[^>]*class=/);
  });

  test("stands out when highlighted", () => {
    expect(render("profile", true)).toContain("border-accent-text/70");
    expect(render("any")).not.toContain("border-accent-text/70");
  });
});
