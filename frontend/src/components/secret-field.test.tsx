import { renderToStaticMarkup } from "react-dom/server";

import { SecretField } from "./secret-field";

describe("SecretField - streamer-safe masked field (story 17.22)", () => {
  test("initial render masks the value - the secret never reaches the markup", () => {
    const html = renderToStaticMarkup(<SecretField label="Mot de passe" value="s3cret-join" />);

    // The security posture: the masked value is absent from the DOM, not hidden with
    // CSS. renderToStaticMarkup shows exactly what a stream capture of the initial
    // state could ever contain.
    expect(html).not.toContain("s3cret-join");
    expect(html).toContain("••••••••");
  });

  test("copy and reveal controls are present while masked", () => {
    const html = renderToStaticMarkup(<SecretField label="Hôte" value="archilan.fr" />);

    expect(html).toContain('aria-label="Copier hôte"');
    expect(html).toContain('aria-label="Afficher hôte"');
  });
});
