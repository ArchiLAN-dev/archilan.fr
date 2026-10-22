import type { Session } from "./admin-sessions-api";
import { sessionUptimeMs } from "./session-uptime";

const NOW = new Date("2026-10-22T12:00:00Z").getTime();

function session(overrides: Partial<Session>): Session {
  return {
    id: "s1",
    eventId: "e1",
    status: "stopped",
    host: null,
    port: null,
    password: null,
    createdAt: "2026-10-22T08:00:00Z",
    startedAt: "2026-10-22T09:00:00Z",
    stoppedAt: null,
    ...overrides,
  };
}

describe("sessionUptimeMs", () => {
  it("counts a running session from its last launch up to now", () => {
    expect(sessionUptimeMs(session({ status: "running" }), NOW)).toBe(3 * 3600 * 1000);
  });

  it("ignores a stop that precedes the last launch on a resumed session", () => {
    const resumed = session({ status: "running", stoppedAt: "2026-10-22T08:30:00Z", startedAt: "2026-10-22T11:00:00Z" });
    expect(sessionUptimeMs(resumed, NOW)).toBe(3600 * 1000);
  });

  it("measures a stopped session up to its stop", () => {
    expect(sessionUptimeMs(session({ stoppedAt: "2026-10-22T10:30:00Z" }), NOW)).toBe(90 * 60 * 1000);
  });

  it("ends a finished session at its end", () => {
    const finished = session({ status: "finished", finishedAt: "2026-10-22T10:00:00Z", stoppedAt: null });
    expect(sessionUptimeMs(finished, NOW)).toBe(3600 * 1000);
  });

  it("shows nothing for a stopped session whose only stop predates its last launch", () => {
    const relaunchedThenStoppedSilently = session({ stoppedAt: "2026-10-22T08:30:00Z" });
    expect(sessionUptimeMs(relaunchedThenStoppedSilently, NOW)).toBeNull();
  });

  it("counts a never-launched session in progress from its creation", () => {
    expect(sessionUptimeMs(session({ status: "launching", startedAt: null }), NOW)).toBe(4 * 3600 * 1000);
  });
});
