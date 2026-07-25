import { renderToStaticMarkup } from "react-dom/server";

import { ConnectionDetails } from "./connection-details";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

describe("ConnectionDetails - per-field streamer masking (story 17.21)", () => {
  const props = {
    host: "archilan.fr",
    port: 38281,
    password: "s3cret-join",
    adminPassword: "s3cret-admin",
  };

  test("initial render masks every value - no secret reaches the markup", () => {
    const html = render(<ConnectionDetails {...props} />);

    // The security posture of the feature: masked values are absent from the DOM,
    // not hidden with CSS. renderToStaticMarkup shows exactly what a stream capture
    // of the initial state could ever contain.
    expect(html).not.toContain("archilan.fr");
    expect(html).not.toContain("38281");
    expect(html).not.toContain("s3cret-join");
    expect(html).not.toContain("s3cret-admin");
    expect(html).toContain("••••••••");
  });

  test("each field row is rendered with its own copy and reveal controls while masked", () => {
    const html = render(<ConnectionDetails {...props} />);

    for (const label of ["hôte", "port", "mot de passe", "mot de passe admin"]) {
      expect(html).toContain(`aria-label="Copier ${label}"`);
      expect(html).toContain(`aria-label="Afficher ${label}"`);
    }
  });

  test("the admin password row is omitted when absent", () => {
    const html = render(<ConnectionDetails {...props} adminPassword={null} />);

    expect(html).not.toContain("mot de passe admin");
    expect(html).toContain('aria-label="Copier mot de passe"');
  });
});
