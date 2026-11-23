import { renderToStaticMarkup } from "react-dom/server";

import { CalibrationNote } from "./admin-game-editor";
import { EventForecastView, formatGap } from "./event-forecast";
import { asDurationCalibration, parseEventForecast, type ForecastSlot } from "./event-forecast-api";

function slot(overrides: Partial<ForecastSlot> = {}): ForecastSlot {
  return {
    slotName: "Alice",
    gameName: "Hollow Knight",
    checksDone: 100,
    locations: 300,
    goalReached: false,
    released: false,
    checksPerHour: 30,
    paceSource: "observed",
    finishAt: "2027-06-02T08:00:00+00:00",
    holdingProgression: 7,
    toRelease: true,
    ...overrides,
  };
}

describe("story 56.8: forecast and calibration", () => {
  it("parses the API's forecast and drops the slots it does not understand", () => {
    const forecast = parseEventForecast({
      data: {
        endsAt: "2027-06-02T10:00:00+00:00",
        live: { sessionId: "s-1", slots: [slot(), { slotName: "Broken" }] },
        calibration: { slots: [{ slotName: "Erin", gameName: "Hollow Knight", estimatedHours: 2, actualHours: 3, gap: 0.5 }], meanGap: 0.5 },
      },
    });
    expect(forecast?.live?.slots).toEqual([slot()]);
    expect(forecast?.calibration.meanGap).toBe(0.5);
    expect(parseEventForecast({ data: { endsAt: "x", live: null } })).toBeNull();
  });

  it("flags the slots to release and points to the existing actions", () => {
    const html = renderToStaticMarkup(
      <EventForecastView
        eventId="ev-1"
        forecast={{
          endsAt: "2027-06-02T10:00:00+00:00",
          live: {
            sessionId: "s-1",
            slots: [slot(), slot({ slotName: "Bob", toRelease: false, paceSource: "estimated", checksPerHour: 60 }), slot({ slotName: "Dave", goalReached: true, toRelease: false })],
          },
          calibration: { slots: [], meanGap: null },
        }}
      />,
    );
    expect(html).toContain("1 slot à libérer");
    expect(html).toContain('href="/admin/evenements/ev-1/session"');
    expect(html).toContain("À libérer (7)");
    expect(html).toContain("estimé");
    expect(html).toContain("Goal atteint");
    expect(html).not.toContain("Prévu et réel");
  });

  it("shows expected against real, and nothing at all when there is neither", () => {
    const html = renderToStaticMarkup(
      <EventForecastView
        eventId="ev-1"
        forecast={{ endsAt: "2027-06-02T10:00:00+00:00", live: null, calibration: { slots: [{ slotName: "Erin", gameName: "HK", estimatedHours: 2, actualHours: 3, gap: 0.5 }], meanGap: 0.5 } }}
      />,
    );
    expect(html).toContain("Écart moyen : +50 %");
    expect(html).not.toContain("En direct");
    expect(formatGap(-0.2)).toBe("-20 %");

    expect(renderToStaticMarkup(<EventForecastView eventId="ev-1" forecast={{ endsAt: "x", live: null, calibration: { slots: [], meanGap: null } }} />)).toBe("");
  });

  it("sums a game up on its admin sheet", () => {
    expect(renderToStaticMarkup(<CalibrationNote calibration={asDurationCalibration({ slots: 4, meanGap: 0.25 })} />)).toContain(
      "en moyenne 25 % de plus que prévu (4 slots)",
    );
    expect(renderToStaticMarkup(<CalibrationNote calibration={asDurationCalibration(null)} />)).toContain("Aucun slot terminé");
  });
});
