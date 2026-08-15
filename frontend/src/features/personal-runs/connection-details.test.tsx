import { renderToStaticMarkup } from "react-dom/server";

import { ConnectionDetails } from "./connection-details";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

describe("ConnectionDetails - per-field streamer masking (story 17.21)", () => {
  const props = {
    host: "archilan.fr",
    port: 35000,
    uri: "wss://archilan.fr:35000",
    password: "s3cret-join",
    adminPassword: "s3cret-admin",
  };

  test("initial render masks every value - no secret reaches the markup", () => {
    const html = render(<ConnectionDetails {...props} />);

    // The security posture of the feature: masked values are absent from the DOM,
    // not hidden with CSS. renderToStaticMarkup shows exactly what a stream capture
    // of the initial state could ever contain.
    expect(html).not.toContain("archilan.fr");
    expect(html).not.toContain("35000");
    expect(html).not.toContain("s3cret-join");
    expect(html).not.toContain("s3cret-admin");
    expect(html).toContain("••••••••");
  });

  test("each field row is rendered with its own copy and reveal controls while masked", () => {
    const html = render(<ConnectionDetails {...props} />);

    const labels = [
      "adresse - client archipelago",
      "adresse - client web",
      "hôte",
      "port",
      "mot de passe",
      "mot de passe admin",
    ];
    for (const label of labels) {
      expect(html).toContain(`aria-label="Copier ${label}"`);
      expect(html).toContain(`aria-label="Afficher ${label}"`);
    }
  });

  test("the admin password row is omitted when absent", () => {
    const html = render(<ConnectionDetails {...props} adminPassword={null} />);

    expect(html).not.toContain("mot de passe admin");
    expect(html).toContain('aria-label="Copier mot de passe"');
  });

  describe("a run launched without a join password (story 16.13)", () => {
    const noPassword = { ...props, password: null };

    test("host and port are still shown - they are what a player needs to connect", () => {
      const html = render(<ConnectionDetails {...noPassword} />);

      expect(html).toContain('aria-label="Copier hôte"');
      expect(html).toContain('aria-label="Copier port"');
    });

    test("the password row gives way to an explanation, not an empty field", () => {
      const html = render(<ConnectionDetails {...noPassword} />);

      expect(html).not.toContain('aria-label="Copier mot de passe"');
      expect(html).toContain("Pas de mot de passe");
    });

    test("the admin password is unaffected - it was never the player's to see", () => {
      const html = render(<ConnectionDetails {...noPassword} />);

      expect(html).toContain('aria-label="Copier mot de passe admin"');
    });
  });
});
