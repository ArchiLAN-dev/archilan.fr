import { renderToStaticMarkup } from "react-dom/server";

import { InfoHint, InfoHintView } from "./info-hint";

function view(open: boolean): string {
  return renderToStaticMarkup(
    <InfoHintView hint="Le pourquoi technique." label="Pourquoi le port ?" onToggle={() => undefined} open={open}>
      Copie l&apos;adresse avec son port.
    </InfoHintView>,
  );
}

/** Story 33.28: the sentence visible, the technical why behind an « i » button. */
describe("InfoHint", () => {
  test("is closed by default: the sentence shows, the explanation is hidden", () => {
    const html = renderToStaticMarkup(<InfoHint hint="Le pourquoi technique.">Copie l&apos;adresse.</InfoHint>);

    expect(html).toContain("Copie l&#x27;adresse.");
    expect(html).toContain('aria-expanded="false"');
    expect(html).toMatch(/class="[^"]*\bhidden\b[^"]*"[^>]*>Le pourquoi technique\./);
  });

  test("open, it shows the explanation and says so", () => {
    const html = view(true);

    expect(html).toContain('aria-expanded="true"');
    expect(html).not.toMatch(/class="[^"]*\bhidden\b[^"]*" id=/);
  });

  test("is a real button named for screen readers, tied to what it unfolds", () => {
    const html = view(false);
    const controls = html.match(/aria-controls="([^"]+)"/)?.[1];

    expect(html).toContain('type="button"');
    expect(html).toContain('aria-label="Pourquoi le port ?"');
    expect(controls).toBeDefined();
    expect(html).toContain(`id="${controls}"`);
  });

  test("names itself « Pourquoi ? » by default", () => {
    expect(renderToStaticMarkup(<InfoHint hint="x">y</InfoHint>)).toContain('aria-label="Pourquoi ?"');
  });
});
