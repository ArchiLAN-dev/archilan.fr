import { renderToStaticMarkup } from "react-dom/server";
import { Popover } from "radix-ui";

import { InfoHint, InfoHintPanel } from "./info-hint";

/** Story 33.28: the sentence visible, the technical why in a panel opened by an « i » button. */
describe("InfoHint", () => {
  test("shows the sentence and keeps the explanation out of the page until asked", () => {
    const html = renderToStaticMarkup(<InfoHint hint="Le pourquoi technique.">Copie l&apos;adresse.</InfoHint>);

    expect(html).toContain("Copie l&#x27;adresse.");
    expect(html).not.toContain("Le pourquoi technique.");
  });

  test("is a real button, named for screen readers, announcing a closed panel", () => {
    const html = renderToStaticMarkup(
      <InfoHint hint="x" label="Pourquoi le port ?">
        y
      </InfoHint>,
    );

    expect(html).toContain('type="button"');
    expect(html).toContain('aria-label="Pourquoi le port ?"');
    expect(html).toContain('aria-haspopup="dialog"');
    expect(html).toContain('aria-expanded="false"');
  });

  test("names itself « Pourquoi ? » by default", () => {
    expect(renderToStaticMarkup(<InfoHint hint="x">y</InfoHint>)).toContain('aria-label="Pourquoi ?"');
  });

  test("the open panel holds the explanation and a way to close it", () => {
    const html = renderToStaticMarkup(
      <Popover.Root open>
        <InfoHintPanel label="Pourquoi ?">Le pourquoi technique.</InfoHintPanel>
      </Popover.Root>,
    );

    expect(html).toContain("Le pourquoi technique.");
    expect(html).toContain('aria-label="Fermer"');
  });
});
