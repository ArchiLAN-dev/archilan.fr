import type { Session } from "./admin-sessions-api";

const LIVE_STATUSES: ReadonlySet<Session["status"]> = new Set(["launching", "running", "restarting"]);

/**
 * How long the server has been up since its last launch, in milliseconds, or `null` when no end can be
 * trusted (story 32.24). `stoppedAt` survives a resume (story 40.3), so a stop that precedes the last launch
 * belongs to an earlier run of the server and is ignored: a live session counts up to `now`, a stopped one shows
 * nothing rather than a duration measured backwards.
 */
export function sessionUptimeMs(session: Session, now: number): number | null {
  const start = new Date(session.startedAt ?? session.createdAt).getTime();
  const end = sessionEnd(session, start, now);
  return end === null ? null : Math.max(0, end - start);
}

function sessionEnd(session: Session, start: number, now: number): number | null {
  if (LIVE_STATUSES.has(session.status)) return now;
  for (const instant of [session.finishedAt, session.stoppedAt]) {
    if (!instant) continue;
    const time = new Date(instant).getTime();
    if (time >= start) return time;
  }
  return null;
}
