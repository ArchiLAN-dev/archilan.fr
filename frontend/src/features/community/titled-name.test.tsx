import { renderToStaticMarkup } from "react-dom/server";

import { isNameStyle, TitledName } from "./titled-name";

/** Story 30.44. Legendary for an admin, epic for a member, the plain name otherwise. */
describe("TitledName", () => {
  test("a plain name without a style", () => {
    expect(renderToStaticMarkup(<TitledName style={null} variant="profile">Ada</TitledName>)).toBe("Ada");
    expect(renderToStaticMarkup(<TitledName style={undefined} variant="card">Ada</TitledName>)).toBe("Ada");
  });

  test("on the profile, the title stands above the name, hidden from screen readers", () => {
    const admin = renderToStaticMarkup(
      <TitledName style="legendary" variant="profile">
        Ada
      </TitledName>,
    );
    expect(admin).toContain('data-name-style="legendary"');
    expect(admin).toMatch(/aria-hidden="true"[^>]*>.*Administrateur<\/span>.*>Ada<\/span>/);

    const member = renderToStaticMarkup(
      <TitledName style="epic" variant="profile">
        Bob
      </TitledName>,
    );
    expect(member).toContain("Adhérent ArchiLAN");
  });

  test("on a card, the emblem before the name and no title", () => {
    const html = renderToStaticMarkup(
      <TitledName style="epic" variant="card">
        Bob
      </TitledName>,
    );
    expect(html).toContain("<svg");
    expect(html).not.toContain("Adhérent");
    expect(html).toMatch(/>Bob<\/span><\/span>$/);
  });

  test("reads the style sent by the API", () => {
    expect(isNameStyle("legendary")).toBe(true);
    expect(isNameStyle("epic")).toBe(true);
    expect(isNameStyle("gold")).toBe(false);
    expect(isNameStyle(null)).toBe(false);
  });
});
