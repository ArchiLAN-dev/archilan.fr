import { renderToStaticMarkup } from "react-dom/server";

import { ConnectionFields } from "./connection-fields";

function render(node: React.ReactElement): string {
  return renderToStaticMarkup(node);
}

const props = {
  host: "archilan.fr",
  port: 35000,
  uri: "wss://archilan.fr:35000",
  password: "s3cret-join",
};

describe("ConnectionFields - les formes d'adresse mesurées en 37.6", () => {
  test("les deux formes sont proposées, parce qu'aucune ne marche partout", () => {
    const html = render(<ConnectionFields {...props} />);

    // Mesuré le 2026-08-14 : l'adresse jointe connecte les clients à champ unique et casse
    // ceux qui ont deux champs. Retirer l'une des deux casse une famille de clients.
    expect(html).toContain('aria-label="Copier adresse - client archipelago"');
    expect(html).toContain('aria-label="Copier adresse - client web"');
    expect(html).toContain('aria-label="Copier hôte"');
    expect(html).toContain('aria-label="Copier port"');
  });

  test("aucune valeur n'atteint le DOM au premier rendu", () => {
    const html = render(<ConnectionFields {...props} />);

    // Un mot de passe visible à l'écran d'un streamer est un mot de passe public.
    expect(html).not.toContain("wss://archilan.fr:35000");
    expect(html).not.toContain("s3cret-join");
    expect(html).toContain("••••••••");
  });

  test("l'aide dit que le port fait partie de l'adresse", () => {
    const html = render(<ConnectionFields {...props} />);

    // Sans port, un client vise 38281 et échoue en une demi-seconde ; l'un d'eux conseille alors
    // de passer sur une version non chiffrée, ce qui envoie le joueur dans une impasse.
    expect(html).toContain("port fait partie de l");
    expect(html).toContain("38281");
  });

  test("ce qu'un client web tiers reçoit est annoncé", () => {
    const html = render(<ConnectionFields {...props} />);

    expect(html).toContain("ne sont pas hébergés par ArchiLAN");
  });

  test("sans URI fournie, la ligne est omise plutôt que fabriquée", () => {
    // L'adresse chiffrée vient de l'API et n'est jamais reconstruite côté client (story 37.4) :
    // une charge utile plus ancienne ne doit pas produire une adresse inventée.
    const html = render(<ConnectionFields {...props} uri={null} />);

    expect(html).not.toContain('aria-label="Copier adresse - client archipelago"');
    expect(html).toContain('aria-label="Copier adresse - client web"');
  });

  test("sans mot de passe, une explication remplace le champ vide", () => {
    const html = render(<ConnectionFields {...props} password={null} />);

    expect(html).not.toContain('aria-label="Copier mot de passe"');
    expect(html).toContain("Pas de mot de passe");
  });
});
