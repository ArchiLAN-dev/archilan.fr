import { parseEventSlotYamlTestResult, parseEventSlotYamlTestStart } from "./event-slot-yaml-test-api";
import { runSlotTestPool, slotTestSummary, slotsToTestAll, stateAfterPoll, stateAfterStart, type SlotTestState } from "./event-slot-yaml-test-queue";

describe("story 11.11 - testing the config of an event slot", () => {
  it("reads a started test, a game that asks for its file, and a refusal", () => {
    expect(parseEventSlotYamlTestStart(true, { data: { jobId: "job-1" } })).toEqual({ kind: "started", jobId: "job-1" });

    const files = [{ setting: "LSWSettings.rom_file", file: "LSW.iso", extension: ".iso", description: null, md5s: [], neededAtGeneration: true }];
    expect(parseEventSlotYamlTestStart(false, { error: { code: "required_file", message: "Fichier", details: { requiredFiles: files } } })).toEqual({
      kind: "requires_file",
      requiredFiles: files,
    });
    expect(parseEventSlotYamlTestStart(false, { error: { code: "runner_unavailable", message: "Le runner est indisponible.", details: [] } })).toEqual({
      kind: "error",
      message: "Le runner est indisponible.",
    });
    expect(parseEventSlotYamlTestStart(false, null).kind).toBe("error");
  });

  it("reads a verdict, an expired test and a broken answer", () => {
    expect(parseEventSlotYamlTestResult(200, { data: { status: "failed", error: "KeyError: DeathLinkStudLoss" } })).toEqual({
      kind: "result",
      status: "failed",
      error: "KeyError: DeathLinkStudLoss",
    });
    expect(parseEventSlotYamlTestResult(404, null)).toEqual({ kind: "gone" });
    expect(parseEventSlotYamlTestResult(200, { data: { status: "weird", error: null } })).toEqual({ kind: "error" });
    expect(parseEventSlotYamlTestResult(503, null)).toEqual({ kind: "error" });
  });

  it("turns a start and a read into the slot's state", () => {
    expect(stateAfterStart({ kind: "started", jobId: "j" })).toEqual({ kind: "running" });
    expect(stateAfterStart({ kind: "requires_file", requiredFiles: [] })).toEqual({ kind: "needs_file", requiredFiles: [] });
    expect(stateAfterPoll({ kind: "result", status: "running", error: null })).toBeNull();
    expect(stateAfterPoll({ kind: "error" })).toBeNull();
    expect(stateAfterPoll({ kind: "result", status: "passed", error: null })).toEqual({ kind: "passed" });
    expect(stateAfterPoll({ kind: "result", status: "failed", error: null })).toEqual({ kind: "failed", error: "Échec de la génération." });
    expect(stateAfterPoll({ kind: "gone" })?.kind).toBe("error");
  });

  it("tests every slot again but those in flight and those waiting for a file", () => {
    const states: Record<string, SlotTestState> = {
      a: { kind: "passed" },
      b: { kind: "running" },
      c: { kind: "needs_file", requiredFiles: [] },
      d: { kind: "failed", error: "x" },
    };
    expect(slotsToTestAll(["a", "b", "c", "d", "e"], states)).toEqual(["a", "d", "e"]);
  });

  it("tallies the verdicts", () => {
    expect(
      slotTestSummary({
        a: { kind: "passed" },
        b: { kind: "passed" },
        c: { kind: "failed", error: "x" },
        d: { kind: "needs_file", requiredFiles: [] },
        e: { kind: "queued" },
        f: { kind: "running" },
        g: { kind: "error", message: "y" },
        h: { kind: "idle" },
      }),
    ).toEqual({ passed: 2, failed: 1, needsFile: 1, pending: 2, errors: 1 });
  });

  it("keeps at most the limit in flight, in order, and stops when the page goes", async () => {
    const started: string[] = [];
    let inFlight = 0;
    let peak = 0;
    const releases: Array<() => void> = [];
    const pool = runSlotTestPool(["a", "b", "c", "d", "e"], 3, async (key) => {
      started.push(key);
      inFlight += 1;
      peak = Math.max(peak, inFlight);
      await new Promise<void>((resolve) => releases.push(resolve));
      inFlight -= 1;
    });

    await Promise.resolve();
    expect(started).toEqual(["a", "b", "c"]);
    while (releases.length > 0) {
      releases.shift()?.();
      await new Promise((resolve) => setTimeout(resolve, 0));
    }
    await pool;
    expect(started).toEqual(["a", "b", "c", "d", "e"]);
    expect(peak).toBe(3);

    const none: string[] = [];
    await runSlotTestPool(["a", "b"], 3, async (key) => {
      none.push(key);
    }, () => false);
    expect(none).toEqual([]);
  });
});
