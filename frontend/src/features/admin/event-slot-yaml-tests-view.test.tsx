/**
 * @jest-environment ./src/tests/jsdom-environment.mjs
 */
import { act, useEffect } from "react";
import { createRoot, type Root } from "react-dom/client";

import type { SessionSlot } from "./admin-sessions-api";
import { EventSlotYamlTestCell, EventSlotYamlTestsToolbar, useEventSlotYamlTests } from "./event-slot-yaml-tests";

const startEventSlotYamlTest = jest.fn();
const getEventSlotYamlTest = jest.fn();
jest.mock("./event-slot-yaml-test-api", () => ({
  startEventSlotYamlTest: (...args: unknown[]) => startEventSlotYamlTest(...args),
  getEventSlotYamlTest: (...args: unknown[]) => getEventSlotYamlTest(...args),
}));

const slots: SessionSlot[] = [
  { id: "s1", sessionId: "sess", registrationId: "r1", gameId: "g1", slotName: "LuDoSniper_SW", slotOrder: 0, slotId: "rs1" },
  { id: "s2", sessionId: "sess", registrationId: "r2", gameId: "g2", slotName: "Maleas_ALT", slotOrder: 1, slotId: "rs2" },
  { id: "s3", sessionId: "sess", registrationId: "r3", gameId: "g3", slotName: "Jean_ALTTP", slotOrder: 2, slotId: "rs3" },
];

let testAll: (() => Promise<void>) | null = null;

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

function Harness() {
  const tests = useEventSlotYamlTests("ev1", slots);
  useEffect(() => {
    testAll = tests.testAll;
  }, [tests.testAll]);
  return (
    <div>
      <EventSlotYamlTestsToolbar slots={slots} tests={tests} />
      {slots.map((slot) => (
        <EventSlotYamlTestCell key={slot.id} slot={slot} tests={tests} />
      ))}
    </div>
  );
}

describe("story 11.11 - the slot tests on the admin session page", () => {
  let container: HTMLDivElement;
  let root: Root;

  beforeEach(() => {
    jest.useFakeTimers();
    container = document.createElement("div");
    document.body.appendChild(container);
    root = createRoot(container);
    startEventSlotYamlTest.mockImplementation(async (_event: string, registrationId: string) => {
      if (registrationId === "r3") return { kind: "requires_file", requiredFiles: [] };
      return { kind: "started", jobId: `job-${registrationId}` };
    });
    getEventSlotYamlTest.mockImplementation(async (_event: string, jobId: string) =>
      jobId === "job-r1"
        ? { kind: "result", status: "failed", error: "KeyError: DeathLinkStudLoss" }
        : { kind: "result", status: "passed", error: null },
    );
  });

  afterEach(() => {
    act(() => root.unmount());
    container.remove();
    jest.useRealTimers();
    testAll = null;
  });

  it("tests every slot, tallies the verdicts and lists the failures", async () => {
    act(() => root.render(<Harness />));
    expect(container.textContent).toContain("Tester toutes les configs");

    await act(async () => {
      const run = testAll?.();
      await jest.advanceTimersByTimeAsync(5000);
      await run;
    });

    expect(startEventSlotYamlTest).toHaveBeenCalledTimes(3);
    expect(container.textContent).toContain("1 testée, 1 en échec, 1 avec fichier à fournir.");
    expect(container.textContent).toContain("LuDoSniper_SW");
    expect(container.textContent).toContain("KeyError: DeathLinkStudLoss");
    expect(container.textContent).toContain("Config testée");
    expect(container.textContent).toContain("Tester avec le fichier");
  });
});
