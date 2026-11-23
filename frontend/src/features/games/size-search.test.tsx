import { http, HttpResponse } from "msw";
import { renderToStaticMarkup } from "react-dom/server";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { SizeSearchView } from "./size-search";
import { parseSizeSearch, startSizeSearch, type SizeSearchState } from "./size-search-api";

const BASE = TEST_API_BASE_URL;

function search(overrides: Partial<SizeSearchState> = {}): SizeSearchState {
  return {
    id: "s-1",
    status: "found",
    targetHours: 19.2,
    maxTries: 6,
    running: false,
    tries: [{ option: "GrubHuntGoal", value: 1, hours: 1 }],
    proposal: { option: "GrubHuntGoal", from: 46, value: 20, hours: 20 },
    error: null,
    ...overrides,
  };
}

const view = (state: SizeSearchState) =>
  renderToStaticMarkup(<SizeSearchView labelOf={() => "Larves à trouver"} onApply={() => undefined} onDismiss={() => undefined} search={state} />);

describe("story 56.7: size search", () => {
  it("parses the API's search and drops what it does not know", () => {
    expect(
      parseSizeSearch({
        data: {
          id: "s-1",
          status: "found",
          targetHours: 19.2,
          maxTries: 6,
          running: false,
          tries: [{ option: "GrubHuntGoal", value: 23, hours: 23 }, { option: 3 }],
          proposal: { option: "GrubHuntGoal", from: 46, value: 20, hours: 20 },
          error: null,
        },
      }),
    ).toEqual(search({ tries: [{ option: "GrubHuntGoal", value: 23, hours: 23 }] }));
    expect(parseSizeSearch({ data: { id: "s-1", status: "exploded" } })).toBeNull();
  });

  it("sends the YAML with its context and returns the API's refusal", async () => {
    let sent: unknown = null;
    server.use(
      http.post(`${BASE}/games/game-hk/size-search`, async ({ request }) => {
        sent = await request.json();
        return HttpResponse.json({ error: { code: "no_size_options", message: "Aucune option de taille n'est désignée pour ce jeu." } }, { status: 409 });
      }),
    );

    expect(await startSizeSearch("game-hk", "name: Jean\n", { eventId: "ev-1" })).toEqual({ ok: false, message: "Aucune option de taille n'est désignée pour ce jeu." });
    expect(sent).toEqual({ yaml: "name: Jean\n", eventId: "ev-1" });
  });

  it("shows the progress, the proposal and the dead end", () => {
    expect(view(search({ status: "running", proposal: null, running: true }))).toContain("essai 2 sur 6 au plus");

    const found = view(search());
    expect(found).toContain("Larves à trouver");
    expect(found).toContain("à 20 au lieu de 46 : environ 20 h (cible 19 h)");
    expect(found).toContain("Appliquer à mon YAML");

    expect(view(search({ status: "not_found", proposal: null }))).toContain("Pas de réglage trouvé en 1 génération");
    expect(view(search({ status: "failed", proposal: null, error: "Le runner est indisponible." }))).toContain("Le runner est indisponible.");
  });
});
