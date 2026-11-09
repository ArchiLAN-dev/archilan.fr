import { renderToStaticMarkup } from "react-dom/server";
import { http, HttpResponse } from "msw";

import { server } from "../../tests/setup";
import { TEST_API_BASE_URL } from "../../tests/constants";
import { decideDeparture, fetchDepartures, fetchMyDeparture, isDeparture, sayILeave, type Departure } from "./departures-api";
import { DeparturesView, LeaveView } from "./departures";

const BASE = TEST_API_BASE_URL;

const departure: Departure = { id: "d1", slotIndex: 2, slotName: "Bob", origin: "player", createdAt: "2026-11-09T14:00:00+01:00" };
const noop = () => Promise.resolve(null);

/** Story 52.6: a player leaves, a decider releases their world or not. */
describe("départ assisté", () => {
  test("the player sees « Je pars » until they said it, then when they did", () => {
    expect(renderToStaticMarkup(<LeaveView onLeave={noop} requestedAt={null} />)).toContain("Je pars");

    const said = renderToStaticMarkup(<LeaveView onLeave={noop} requestedAt="2026-11-09T14:00:00+01:00" />);
    expect(said).not.toContain("Je pars");
    expect(said).toContain("Départ signalé à");
  });

  test("the deciders see who left and why, with release and dismiss", () => {
    const html = renderToStaticMarkup(<DeparturesView departures={[departure, { ...departure, id: "d2", slotName: "Zoé", origin: "schedule" }]} onDecide={noop} />);

    expect(html).toContain("Départs à traiter");
    expect(html).toContain("a signalé son départ");
    expect(html).toContain("départ déclaré passé, déconnecté");
    expect(html.match(/Libérer son monde/g)).toHaveLength(2);
    expect(html.match(/Il revient/g)).toHaveLength(2);
  });

  test("API: reads, says one leaves, lists and decides, relaying refusals", async () => {
    const decided: string[] = [];
    server.use(
      http.get(`${BASE}/sessions/s1/slots/2/departure`, () => HttpResponse.json({ data: { available: true, requestedAt: null } })),
      http.post(`${BASE}/sessions/s1/slots/2/departure`, () => HttpResponse.json({ error: { code: "departure_already_requested", message: "Ton départ est déjà signalé." } }, { status: 409 })),
      http.get(`${BASE}/sessions/s1/departures`, () => HttpResponse.json({ data: [departure] })),
      http.post(`${BASE}/sessions/s1/departures/d1/:decision`, ({ params }) => {
        decided.push(String(params.decision));
        return params.decision === "release"
          ? HttpResponse.json({ error: { code: "bridge_unavailable", message: "Bridge non disponible." } }, { status: 503 })
          : new HttpResponse(null, { status: 204 });
      }),
    );

    expect(await fetchMyDeparture("s1", "2")).toEqual({ available: true, requestedAt: null });
    expect(await sayILeave("s1", "2")).toBe("Ton départ est déjà signalé.");
    expect(await fetchDepartures("s1")).toEqual([departure]);
    expect(await decideDeparture("s1", "d1", "release")).toBe("Bridge non disponible.");
    expect(await decideDeparture("s1", "d1", "dismiss")).toBeNull();
    expect(decided).toEqual(["release", "dismiss"]);
    expect(isDeparture({ ...departure, origin: "admin" })).toBe(false);
  });
});
