import { renderToStaticMarkup } from "react-dom/server";

import { parseBalanceReport, type BalanceReport } from "./balance-api";
import { balanceStateLine, BalanceReportView } from "./balance-report";

function report(overrides: Partial<BalanceReport> = {}): BalanceReport {
  return {
    status: "done",
    requestedAt: "2026-11-23T10:00:00+00:00",
    finishedAt: "2026-11-23T10:02:00+00:00",
    stale: false,
    error: null,
    missingFiles: [],
    targetHours: 19.2,
    medianHours: 6,
    slotCount: 2,
    simulation: { status: "done", endHours: 31.5, criticalSlot: "BobGiant" },
    slots: [
      {
        slotName: "BobGiant",
        playerName: "Bob",
        userId: "u-bob",
        gameName: "Giant",
        measured: true,
        locations: 800,
        hostedShare: 0.875,
        fairShare: 0.8,
        othersShare: 0.875,
        hours: 24,
        durationSource: "admin",
        medianGap: 4,
        alerts: ["duration_over_median", "hosted_over_half"],
        simulation: { finishHours: 31.5, bkHours: 7.5, waitsOn: [{ slotName: "AliceShort", hours: 7.5 }], paceAssumed: true },
      },
    ],
    ...overrides,
  };
}

describe("story 56.3: balance report", () => {
  it("parses the API's report and drops what it does not know", () => {
    const parsed = parseBalanceReport({
      data: {
        status: "done",
        stale: true,
        missingFiles: ["DKC3", 4],
        targetHours: null,
        medianHours: 2.5,
        slotCount: 1,
        slots: [
          {
            slotName: "A",
            playerName: "Alice",
            userId: "u-a",
            gameName: "Game A",
            measured: true,
            locations: 100,
            hostedShare: 0.1,
            fairShare: 0.2,
            othersShare: null,
            medianGap: 1,
            duration: { hours: 2.5, source: "measured" },
            alerts: ["hosted_over_fair", "made_up"],
            simulation: { finishHours: 4, bkHours: 1.5, waitsOn: [{ slotName: "B", hours: 1.5 }, { nope: 1 }], paceAssumed: false },
          },
          { slotName: "broken" },
        ],
      },
    });

    expect(parsed?.stale).toBe(true);
    expect(parsed?.missingFiles).toEqual(["DKC3"]);
    expect(parsed?.slots).toHaveLength(1);
    expect(parsed?.slots[0]?.hours).toBe(2.5);
    expect(parsed?.slots[0]?.alerts).toEqual(["hosted_over_fair"]);
    expect(parsed?.slots[0]?.simulation).toEqual({ finishHours: 4, bkHours: 1.5, waitsOn: [{ slotName: "B", hours: 1.5 }], paceAssumed: false });
    expect(parsed?.simulation).toBeNull();
    expect(parseBalanceReport({ data: { status: "exploded" } })).toBeNull();
  });

  it("shows each slot with its duration, its share and its alerts", () => {
    const html = renderToStaticMarkup(<BalanceReportView report={report()} />);

    expect(html).toContain("Bob");
    expect(html).toContain("800 emplacements");
    expect(html).toContain("24 h");
    expect(html).toContain("estimation admin");
    expect(html).toContain("87.5 %");
    expect(html).toContain("Plus de 2 fois la durée médiane");
    expect(html).toContain("Héberge plus de la moitié de la progression des autres");
    expect(html).toContain("durée visée 19 h");
  });

  it("says when the report is to redo, and keeps the party's summary from a player", () => {
    const html = renderToStaticMarkup(<BalanceReportView mine report={report({ stale: true })} />);

    expect(html).toContain("À refaire");
    expect(renderToStaticMarkup(<BalanceReportView report={report({ slots: [{ ...report().slots[0]!, hours: null, durationSource: "unknown", alerts: [] }] })} />)).toContain("durée inconnue");
    expect(html).not.toContain("Durée médiane");
  });

  it("shows the simulated course and the party's critical slot (story 56.4)", () => {
    const html = renderToStaticMarkup(<BalanceReportView report={report()} />);

    expect(html).toContain("Fin de partie simulée vers 32 h");
    expect(html).toContain("la partie attend <span class=\"font-semibold\">Bob</span> (Giant)");
    expect(html).toContain("fin vers 32 h");
    expect(html).toContain("BK 7,5 h (rythme médian supposé)");
    expect(html).toContain("attend AliceShort (7,5 h)");
    expect(renderToStaticMarkup(<BalanceReportView report={report({ simulation: { status: "missing", endHours: null, criticalSlot: null } })} />)).toContain(
      "Pas de déroulé simulé",
    );
    // A player reads their own course, never the party's summary.
    expect(renderToStaticMarkup(<BalanceReportView mine report={report()} />)).not.toContain("Fin de partie simulée");
  });

  it("names the games that need a ROM instead of a table", () => {
    expect(balanceStateLine(report({ status: "needs_files", missingFiles: ["Donkey Kong Country 3"], slots: [] }))).toBe(
      "Génération impossible sans fichier : Donkey Kong Country 3 demande une ROM à la génération.",
    );
    expect(balanceStateLine(report({ status: "failed", error: "Bob : YAML invalide" }))).toBe("Bob : YAML invalide");
    expect(balanceStateLine(report())).toBeNull();
  });
});
