import { renderToStaticMarkup } from "react-dom/server";

import { YamlOptionEditor } from "./yaml-option-editor";

/** Story 9.57: every option editor offers to import a YAML and, when the game is known, to test it. */
const DEFAULT_YAML = `name: Player{number}
game: Hollow Knight
Hollow Knight:
  RandomizeSkills:
    false: 0
    true: 50
`;

describe("YamlOptionEditor - import et test de YAML", () => {
  test("un éditeur modifiable propose l'import, et le test quand le jeu est connu", () => {
    const html = renderToStaticMarkup(
      <YamlOptionEditor defaultYaml={DEFAULT_YAML} gameId="game-hk" playerYaml={null} onChange={() => undefined} />,
    );

    expect(html).toContain("Importer un YAML");
    expect(html).toContain("Tester la génération");
  });

  test("sans jeu connu, pas de bouton de test", () => {
    const html = renderToStaticMarkup(<YamlOptionEditor defaultYaml={DEFAULT_YAML} playerYaml={null} onChange={() => undefined} />);

    expect(html).toContain("Importer un YAML");
    expect(html).not.toContain("Tester la génération");
  });

  test("un éditeur fermé aux modifications ne propose pas l'import", () => {
    const html = renderToStaticMarkup(
      <YamlOptionEditor defaultYaml={DEFAULT_YAML} gameId="game-hk" playerYaml={null} registrationId="r-1" registrationOpen={false} slotId="s-1" />,
    );

    expect(html).not.toContain("Importer un YAML");
  });
});
